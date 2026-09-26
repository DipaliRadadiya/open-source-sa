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
 * PrestaShop's distribution API, in the shape it really answers with
 * (api.prestashop-project.org/prestashop, read 2026-09-26) — trimmed, and
 * deliberately NOT sorted: the real list is newest first today, and nothing
 * promises it stays that way.
 */
function fakePrestaShopReleases(bool $ok = true): void
{
    $release = fn (string $version, string $stability, string $min, string $max, string $url) => [
        'version' => $version, 'stability' => $stability,
        'php_min_version' => $min, 'php_max_version' => $max,
        'zip_download_url' => $url, 'zip_md5' => md5($version),
    ];
    $classic = fn (string $path) => "https://api.prestashop-project.org/assets/prestashop-classic/{$path}/prestashop.zip";
    $open = fn (string $version) => "https://api.prestashop-project.org/assets/prestashop/{$version}/prestashop.zip";

    Http::fake(['api.prestashop-project.org/*' => $ok
        ? Http::response([
            $release('8.2.8', 'stable', '7.2.5', '8.1', $open('8.2.8')),
            $release('9.2.0-rc.1', 'rc', '8.1', '8.5', $classic('9.2.0-6.0-rc.1')),
            $release('9.1.4', 'stable', '8.1', '8.5', $classic('9.1.4-5.0')),
            // Newer than everything, and plain http: the download step refuses
            // it, so choosing it would only fail later.
            $release('9.1.9', 'stable', '8.1', '8.5', 'http://example.com/prestashop.zip'),
            $release('9.1.5', 'stable', '8.1', '8.5', $classic('9.1.5-5.0')),
            $release('9.0.3', 'stable', '8.1', '8.4', $classic('9.0.3-3.0')),
            $release('1.7.8.11', 'stable', '7.1.3', '7.4', $open('1.7.8.11')),
        ])
        : Http::response('', 500),
    ]);
}

/**
 * @param  int|null  $port  a port MySQL is NOT assumed to be on
 */
function installPrestaShop(?int $port = null): ArrayObject
{
    $runs = new ArrayObject;

    if ($port !== null) {
        moveDatabasePort('mysql', $port);
    }

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input, 'path' => $process->path];

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    return $runs;
}

function prestaShopDownload(): string
{
    $curl = collect(installPrestaShop())->first(fn ($run) => ($run['command'][0] ?? '') === 'curl')['command'];

    return (string) end($curl);
}

it('takes the newest stable release that runs on the site\'s PHP', function () {
    fakePrestaShopReleases();

    // 8.4 is past PrestaShop 8's ceiling of 8.1 — the release the old feed
    // named, and the one that died on 8.5 on 2026-09-08. Not the release
    // candidate, not the http entry, and not simply the first in the list.
    expect(prestaShopDownload())
        ->toBe('https://api.prestashop-project.org/assets/prestashop-classic/9.1.5-5.0/prestashop.zip');
});

it('gives an older PHP the last release that still runs on it', function () {
    $this->application->forceFill(['php_version' => '7.4'])->save();
    fakePrestaShopReleases();

    expect(prestaShopDownload())
        ->toBe('https://api.prestashop-project.org/assets/prestashop/8.2.8/prestashop.zip');
});

it('compares the floor as major.minor, so 7.2 meets 7.2.5', function () {
    // The site holds `7.2`; version_compare('7.2', '7.2.5') says it is older.
    $this->application->forceFill(['php_version' => '7.2'])->save();
    fakePrestaShopReleases();

    expect(prestaShopDownload())->toEndWith('/prestashop/8.2.8/prestashop.zip');
});

it('chooses for the server default when the site names no PHP', function () {
    // Same resolution the installer's own `php` runs under, so the release and
    // the interpreter cannot disagree.
    $this->application->forceFill(['php_version' => null])->save();
    config(['server.default_php_version' => '8.0']);
    fakePrestaShopReleases();

    expect(prestaShopDownload())->toEndWith('/prestashop/8.2.8/prestashop.zip');
});

it('records the release it installed, and that release\'s PHP range', function () {
    fakePrestaShopReleases();
    installPrestaShop();

    // The type reaches 8.5 only because 9.x does; this shop has one release,
    // and the PHP screen must hold it to that one.
    expect($this->application->fresh()->settings)
        ->toMatchArray(['prestashop_version' => '9.1.5', 'php_range' => ['min' => '8.1', 'max' => '8.5']]);
});

it('records PrestaShop 8\'s range for a shop given PrestaShop 8', function () {
    $this->application->forceFill(['php_version' => '7.4'])->save();
    fakePrestaShopReleases();
    installPrestaShop();

    expect($this->application->fresh()->settings['php_range'])->toBe(['min' => '7.2', 'max' => '8.1']);
});

it('records no range for a package pinned by the operator', function () {
    // Nothing is known about a pinned package, so the shop keeps the
    // PrestaShop 8 ceiling rather than a guess.
    config(['server.installers.prestashop.download_url' => 'https://mirror.example.com/prestashop.zip']);
    Http::fake();
    installPrestaShop();

    expect($this->application->fresh()->settings)->not->toHaveKey('php_range');
    Http::assertNothingSent();
});

it('stops when no release runs on the site\'s PHP', function () {
    $this->application->forceFill(['php_version' => '7.0'])->save();
    fakePrestaShopReleases();
    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    expect(fn () => app(ApplicationProvisioner::class)->provision($this->application))
        ->toThrow(ProvisioningFailedException::class);
    expect(collect($runs)->contains(fn ($command) => ($command[0] ?? '') === 'curl'))->toBeFalse();
});

it('stops when the release list cannot be read', function () {
    fakePrestaShopReleases(ok: false);
    Process::fake();

    // Rather than download whatever else answers and unpack it into a live
    // web root.
    expect(fn () => app(ApplicationProvisioner::class)->provision($this->application))
        ->toThrow(ProvisioningFailedException::class);
});

it('unpacks the archive inside the archive', function () {
    fakePrestaShopReleases();
    $commands = collect(installPrestaShop())->pluck('command');

    // The published zip contains a single `prestashop.zip`. One unzip leaves
    // an archive in the web root rather than a shop.
    expect($commands)->toContain(['unzip', '-q', '-o', "{$this->docRoot}/prestashop.zip", '-d', $this->docRoot])
        ->and($commands)->toContain(['rm', '-f', "{$this->docRoot}/prestashop.zip"]);
});

it('removes the install wizard once the shop is up', function () {
    fakePrestaShopReleases();

    // Upstream requires it: left in place, it is a working installer on a
    // public URL.
    expect(collect(installPrestaShop())->pluck('command'))
        ->toContain(['rm', '-rf', "{$this->docRoot}/install"]);
});

it('never lets a retry drop the tables of a shop that already installed', function () {
    fakePrestaShopReleases();
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
    fakePrestaShopReleases();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    foreach ($command as $argument) {
        expect($argument)->not->toStartWith('--license');
    }
});

it('passes the passwords as arguments, which is documented and deliberate', function () {
    fakePrestaShopReleases();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // index_cli.php reads $argv and never touches stdin — there is no prompt
    // to answer, so unlike every other installer here this one cannot keep
    // secrets off the command line. Asserted so the exception stays visible
    // rather than being mistaken for an oversight.
    expect($command)->toContain('--password=ShopPass1234!')
        ->and(collect($command)->contains(fn ($a) => str_starts_with((string) $a, '--db_password=')))->toBeTrue();
});

it('puts a moved port in --db_server, which is where PrestaShop wants it', function () {
    fakePrestaShopReleases();
    $command = collect(installPrestaShop(25060))
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // PrestaShop's CLI has no --db_port; its own docs say "if your MySQL
    // server is configured on a different port than 3306, please specify it
    // in the db_server argument like this: --db_server=sql.example.com:3307".
    expect($command)->toContain('--db_server=127.0.0.1:25060')
        ->and(collect($command)->contains(fn ($a) => str_starts_with((string) $a, '--db_port')))->toBeFalse();
});

it('leaves --db_server bare on a stock database', function () {
    fakePrestaShopReleases();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // 3306 is the port PrestaShop assumes, and every shop the panel has
    // installed was given a bare host.
    expect($command)->toContain('--db_server=127.0.0.1');
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
    fakePrestaShopReleases();

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

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
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

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
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
