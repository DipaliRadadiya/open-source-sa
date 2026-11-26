<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\Installers\PrestaShopInstaller;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;

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
 * PrestaShop's distribution API (api.prestashop-project.org/prestashop) in the shape it really
 * answers with, trimmed and deliberately NOT sorted: the real list is newest first today, and
 * nothing promises it stays that way.
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

    // 8.4 is past PrestaShop 8's ceiling of 8.1. Not the release candidate, not the http entry,
    // and not simply the first in the list.
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

    // db_clear defaults to 1, which drops existing tables: a second attempt against a working shop
    // would lose everything, and a fresh database has nothing to clear.
    expect($command)->toContain('--db_clear=0');
});

it('never passes --license, which prints the licence instead of accepting it', function () {
    // It reads as "accept the licence" and does the opposite: datas.php defines it as
    // `'show_license' => [... 'help' => 'show PrestaShop license']`. `--license=1` made the
    // installer print the licence and exit 0 having created nothing, so the panel removed
    // `install/` and the shop answered `"install" directory is missing`.
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

    // index_cli.php reads $argv and never stdin, so unlike the other installers this one cannot
    // keep secrets off the command line. Asserted so the exception is not mistaken for an
    // oversight.
    expect($command)->toContain('--password=ShopPass1234!')
        ->and(collect($command)->contains(fn ($a) => str_starts_with((string) $a, '--db_password=')))->toBeTrue();
});

it('puts a moved port in --db_server, which is where PrestaShop wants it', function () {
    fakePrestaShopReleases();
    $command = collect(installPrestaShop(25060))
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // PrestaShop's CLI has no --db_port; a non-3306 port goes in the db_server argument
    // (`--db_server=sql.example.com:3307`).
    expect($command)->toContain('--db_server=127.0.0.1:25060')
        ->and(collect($command)->contains(fn ($a) => str_starts_with((string) $a, '--db_port')))->toBeFalse();
});

it('leaves --db_server bare on a stock database', function () {
    fakePrestaShopReleases();
    $command = collect(installPrestaShop())
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    // 3306 is the port PrestaShop assumes, so the host stays bare.
    expect($command)->toContain('--db_server=127.0.0.1');
});

it('checks the shop was really installed before removing the wizard', function () {
    // `index_cli.php` can exit 0 having written no configuration. Trusting that, the harden step
    // removed `install/` and the shop answered `"install" directory is missing`, with the wizard
    // that could have finished the job gone. The config is checked before that irreversible step,
    // and a failure leaves `install/` alone so the site can be rescued in a browser.
    fakePrestaShopReleases();

    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command];

        // Everything succeeds except the check for a written config.
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
    // PrestaShop keeps its own copy of the address in the database. Without updating it, a shop
    // issued a certificate served https pages with http images and links, and the browser dropped
    // the padlock.
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    app(PrestaShopInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'https://shop.example.com/');

    $sync = collect($runs)->first(fn ($run) => in_array('-r', $run['command'], true));

    expect($sync)->not->toBeNull();

    // As the site user: the panel does not hold this database's password; only the shop does.
    expect($sync['command'][0])->toBe('runuser');

    // The host and scheme travel on stdin, never inside the program: the domain is user input, and
    // interpolating it would build code from it.
    $input = json_decode($sync['input'], true);

    expect($input['domain'])->toBe('shop.example.com')
        ->and($input['ssl'])->toBe(1)
        ->and($input['parameters'])->toEndWith('/app/config/parameters.php')
        ->and($sync['command'])->not->toContain('shop.example.com');
});

it('counts matched rows, so re-applying the address a shop already has succeeds', function () {
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 0);
    });

    app(PrestaShopInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'https://shop.example.com/');

    $command = collect($runs)->first(fn ($command) => in_array('-r', $command, true));
    $program = $command[array_search('-r', $command, true) + 1];

    // MySQL counts changed rows by default, so re-applying the same address (sites:resync does,
    // on every deploy) got 0 and failed a correct shop.
    expect($program)->toContain('Pdo\\\\Mysql::ATTR_FOUND_ROWS')
        ->and($program)->toContain('PDO::MYSQL_ATTR_FOUND_ROWS')
        ->and($program)->toContain('$foundRows => true')
        // Still refuses a shop whose rows were not found at all.
        ->and($program)->toContain('rowCount() === 0');

    $file = tempnam(sys_get_temp_dir(), 'ps-sync');
    file_put_contents($file, "<?php\n".$program);
    exec('php -l '.escapeshellarg($file).' 2>&1', $output, $exit);
    unlink($file);

    expect($exit)->toBe(0);
});

it('turns the SSL flags back off when the certificate goes away', function () {
    // RemoveCertificate calls syncUrl with the http:// URL. A shop still claiming SSL after its
    // certificate is gone redirects to an address that no longer answers.
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

    // An empty domain column is a shop that generates links to nowhere, yet the write would
    // report success.
    expect(fn () => app(PrestaShopInstaller::class)
        ->syncUrl($this->application->fresh(['systemUser']), 'not-a-url'))
        ->toThrow(RuntimeException::class);
});

/*
| PrestaShop's CLI has no `--shop_name`: datas.php maps the `shop_name` setting to `--name` and
| ignores unknown options, so every shop was called "PrestaShop".
*/
it('names the shop with --name, the option PrestaShop actually reads', function () {
    fakePrestaShopReleases();
    $runs = installPrestaShop();
    $command = collect($runs)
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    expect($command)->toContain('--name=Acme Shop')
        ->and(collect($command)->contains(fn ($a) => str_starts_with((string) $a, '--shop_name')))->toBeFalse();
});

it('cleans the site name it falls back to when no shop name was given', function () {
    $this->application->forceFill([
        'name' => 'Acme = {Best} Shop',
        'settings' => ['admin_email' => 'shop@acme.test', 'admin_password' => 'ShopPass1234!'],
    ])->save();

    fakePrestaShopReleases();
    $runs = installPrestaShop();
    $command = collect($runs)
        ->first(fn ($run) => in_array('install/index_cli.php', $run['command'], true))['command'];

    expect($command)->toContain('--name=Acme  Best Shop');
});

it('refuses a shop name PrestaShop would drop or reject', function (string $name, bool $accepted) {
    $rules = ['shop_name' => app(SiteTypeManager::class)->find('prestashop')->rules()['shop_name']];
    $validator = Validator::make(['shop_name' => $name], $rules);

    expect($validator->fails())->toBe(! $accepted);

    if (! $accepted) {
        expect($validator->errors()->first('shop_name'))->toBe(__('errors/application.shop_name_characters'));
    }
})->with([
    'plain' => ['Acme Shop', true],
    'accents and symbols PrestaShop allows' => ['Café & Co. — 100% «bio»', true],
    'equals (dropped by the CLI parser)' => ['A=B', false],
    'pipe (dropped by the CLI parser)' => ['A|B', false],
    'backtick (dropped by the CLI parser)' => ['A`B', false],
    'angle bracket (fails isGenericName)' => ['<b>Shop</b>', false],
    'brace (fails isGenericName)' => ['Shop {1}', false],
]);
