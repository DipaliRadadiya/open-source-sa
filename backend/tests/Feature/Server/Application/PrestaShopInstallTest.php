<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-ps-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'psuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'site_type' => 'prestashop',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'settings' => [
            'shop_name' => 'Acme Shop',
            'admin_email' => 'shop@acme.test',
            'admin_password' => 'ShopPass1234!',
        ],
    ]);

    $this->docRoot = "{$this->home}/shop.example.com";
});

/**
 * PrestaShop's own channel feed. Branches are listed oldest first, so the
 * last one is what upstream currently calls stable.
 */
function fakePrestaShopFeed(bool $ok = true): void
{
    Http::fake(['api.prestashop.com/*' => $ok
        ? Http::response(
            '<prestashop><channel name="stable">'
            .'<branch name="1.6"><link>http://download.prestashop.com/download/releases/prestashop_1.6.1.24.zip</link></branch>'
            .'<branch name="1.7"><link>https://github.com/PrestaShop/PrestaShop/releases/download/1.7.8.11/prestashop_1.7.8.11.zip</link></branch>'
            .'<branch name="8.2"><link>https://github.com/PrestaShop/PrestaShop/releases/download/8.2.1/prestashop_8.2.1.zip</link></branch>'
            .'</channel></prestashop>'
        )
        : Http::response('', 500),
    ]);
}

function installPrestaShop(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

it('takes the current stable branch from PrestaShop\'s own feed', function () {
    fakePrestaShopFeed();
    $runs = installPrestaShop();

    $curl = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    // Their 9.x tags publish no package at all, and the feed is what their
    // own updater follows — so this picks up a new stable branch without a
    // code change, and never resolves to a release that has no download.
    expect(end($curl))->toBe('https://github.com/PrestaShop/PrestaShop/releases/download/8.2.1/prestashop_8.2.1.zip');
});

it('skips a branch that is not served over https', function () {
    fakePrestaShopFeed();
    $curl = collect(installPrestaShop())->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    // The 1.6 entry in the real feed is plain http; the download step refuses
    // anything but https, so choosing it would fail at the fetch.
    expect(end($curl))->not->toStartWith('http://');
});

it('stops when the feed cannot be read', function () {
    fakePrestaShopFeed(ok: false);
    Process::fake();

    // Rather than download whatever else answers and unpack it into a live
    // web root.
    expect(fn () => app(ApplicationProvisioner::class)->provision($this->application))
        ->toThrow(ProvisioningFailedException::class);
});

it('unpacks the archive inside the archive', function () {
    fakePrestaShopFeed();
    $commands = collect(installPrestaShop())->pluck('command');

    // The published zip contains a single `prestashop.zip`. One unzip leaves
    // an archive in the web root rather than a shop.
    expect($commands)->toContain(['unzip', '-q', '-o', "{$this->docRoot}/prestashop.zip", '-d', $this->docRoot])
        ->and($commands)->toContain(['rm', '-f', "{$this->docRoot}/prestashop.zip"]);
});

it('removes the install wizard once the shop is up', function () {
    fakePrestaShopFeed();

    // Upstream requires it: left in place, it is a working installer on a
    // public URL.
    expect(collect(installPrestaShop())->pluck('command'))
        ->toContain(['rm', '-rf', "{$this->docRoot}/install"]);
});

it('never lets a retry drop the tables of a shop that already installed', function () {
    fakePrestaShopFeed();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // db_clear defaults to 1 — dropping existing tables. The database is ours
    // and freshly created, so there is nothing to clear and everything to
    // lose if a second attempt runs against a working shop.
    expect($command)->toContain('--db_clear=0')
        ->and($command)->toContain('--license=1');
});

it('passes the passwords as arguments, which is documented and deliberate', function () {
    fakePrestaShopFeed();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // index_cli.php reads $argv and never touches stdin — there is no prompt
    // to answer, so unlike every other installer here this one cannot keep
    // secrets off the command line. Asserted so the exception stays visible
    // rather than being mistaken for an oversight.
    expect($command)->toContain('--password=ShopPass1234!')
        ->and(collect($command)->contains(fn ($a) => str_starts_with((string) $a, '--db_password=')))->toBeTrue();
});
