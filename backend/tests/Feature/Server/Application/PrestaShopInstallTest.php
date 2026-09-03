<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\Installers\PrestaShopInstaller;
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

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
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

    $this->docRoot = "{$this->home}/shop/public_html";
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
            // Last in the real feed, and not PrestaShop: the autoupgrade
            // module ships its own branch here. Absent from this fixture, the
            // suite happily passed while every install downloaded the module.
            .'<branch name="autoupgrade"><link>https://github.com/PrestaShop/autoupgrade/releases/download/v6.2.0/autoupgrade-v6.2.0.zip</link></branch>'
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
    expect($command)->toContain('--db_clear=0');
});

it('never passes --license, which prints the licence instead of accepting it', function () {
    // It reads as "accept the licence" and does the opposite. PrestaShop's own
    // datas.php defines it as
    // `'show_license' => ['name' => 'license', 'default' => 0,
    //  'help' => 'show PrestaShop license']`.
    //
    // So `--license=1` told the installer to print the licence and stop. Every
    // install exited 0 in a third of a second having created nothing, the
    // panel took that as success and removed `install/`, and the shop answered
    // `"install" directory is missing` for good.
    fakePrestaShopFeed();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    foreach ($command as $argument) {
        expect($argument)->not->toStartWith('--license');
    }
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

it('ignores the autoupgrade module, which the feed lists last', function () {
    // The feed carries more than PrestaShop. Taking the final branch — "oldest
    // first, so the last one is current" — downloaded the autoupgrade module,
    // an archive with no `prestashop.zip` inside it. The install then failed at
    // the second unzip with "cannot find or open .../prestashop.zip", on a site
    // whose files and database had already been created.
    fakePrestaShopFeed();

    $curl = collect(installPrestaShop())->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    expect(end($curl))->not->toContain('autoupgrade')
        ->and(end($curl))->toBe('https://github.com/PrestaShop/PrestaShop/releases/download/8.2.1/prestashop_8.2.1.zip');
});

it('checks the shop was really installed before removing the wizard', function () {
    // `index_cli.php` has exited 0 in half a second having written no
    // configuration at all. Trusting that, the harden step removed `install/`,
    // and every request to the shop then answered `"install" directory is
    // missing` — the wizard that could have finished the job was gone.
    //
    // So the config is checked before the irreversible step, and a failure
    // leaves `install/` alone: the difference between a site somebody can
    // rescue in a browser and one that can only be deleted.
    fakePrestaShopFeed();

    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command];

        // Everything succeeds except the check for a written config — the exact
        // shape of the failure seen on a real server.
        return in_array('test', $process->command, true) && in_array('-f', $process->command, true)
            ? Process::result(exitCode: 1)
            : Process::result(exitCode: 0);
    });

    expect(fn () => app(ApplicationProvisioner::class)->provision(test()->application))
        ->toThrow(ProvisioningFailedException::class);

    $removedWizard = collect($runs)->contains(
        fn ($run) => ($run['command'][0] ?? '') === 'rm'
            && str_ends_with((string) end($run['command']), '/install'),
    );

    expect($removedWizard)->toBeFalse();
});

it('updates the shop URL and the SSL flags when a certificate is issued', function () {
    // PrestaShop keeps its own copy of the address, in the database, and
    // `install/index_cli.php --domain=` was the only thing that ever wrote it.
    // A shop issued a certificate served pages over https while every image
    // and generated link still pointed at http — the browser sees the mix and
    // drops the padlock, and the certificate was never the problem.
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return Process::result(exitCode: 0);
    });

    app(PrestaShopInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'https://shop.example.com/');

    $sync = collect($runs)->first(fn ($run) => in_array('-r', $run['command'], true));

    expect($sync)->not->toBeNull();

    // As the site user: the panel does not hold this database's password, and
    // the only account that still can is the shop itself.
    expect($sync['command'][0])->toBe('runuser');

    // The host and the scheme travel on stdin, never inside the program. The
    // domain is user input, and interpolating it would be building code from
    // it — the same reason the queries bind rather than concatenate.
    $input = json_decode($sync['input'], true);

    expect($input['domain'])->toBe('shop.example.com')
        ->and($input['ssl'])->toBe(1)
        ->and($input['parameters'])->toEndWith('/app/config/parameters.php')
        ->and($sync['command'])->not->toContain('shop.example.com');
});

it('turns the SSL flags back off when the certificate goes away', function () {
    // RemoveCertificate calls syncUrl with the http:// URL. A shop left
    // claiming SSL after its certificate is gone redirects to an address that
    // no longer answers, which is a shop that cannot be reached at all.
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return Process::result(exitCode: 0);
    });

    app(PrestaShopInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'http://shop.example.com');

    $sync = collect($runs)->first(fn ($run) => in_array('-r', $run['command'], true));

    expect(json_decode($sync['input'], true)['ssl'])->toBe(0);
});

it('refuses a URL with no host rather than blanking the shop domain', function () {
    Process::fake(fn () => Process::result(exitCode: 0));

    // An empty domain column is a shop that generates links to nowhere, and
    // the write would report success.
    expect(fn () => app(PrestaShopInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'not-a-url'))
        ->toThrow(RuntimeException::class);
});
