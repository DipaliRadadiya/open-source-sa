<?php

use App\Jobs\InstallPhpExtension;
use App\Models\User;
use App\Services\Server\Php\PhpExtensionManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->panel = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $this->other = '8.1';

    $this->phpDir = sys_get_temp_dir().'/sv-oss-phpext-'.getmypid();
    $this->soDir = $this->phpDir.'/lib';
    File::deleteDirectory($this->phpDir);

    // A real Debian layout in miniature: mods-available holds what is
    // installed, each SAPI's conf.d holds what is switched on.
    foreach ([$this->panel, $this->other] as $version) {
        File::makeDirectory("{$this->phpDir}/{$version}/mods-available", 0755, true);
        foreach (['cli', 'fpm'] as $sapi) {
            File::makeDirectory("{$this->phpDir}/{$version}/{$sapi}/conf.d", 0755, true);
        }
        foreach (['curl', 'mysqli', 'pdo_mysql', 'mysqlnd', 'redis', 'mbstring'] as $module) {
            File::put("{$this->phpDir}/{$version}/mods-available/{$module}.ini", "extension={$module}.so\n");
            foreach (['cli', 'fpm'] as $sapi) {
                File::put("{$this->phpDir}/{$version}/{$sapi}/conf.d/20-{$module}.ini", '');
            }
        }
    }

    File::makeDirectory($this->soDir, 0755, true);
    foreach (['curl', 'mysqli', 'pdo_mysql', 'mysqlnd', 'redis', 'mbstring'] as $module) {
        File::put("{$this->soDir}/{$module}.so", '');
    }

    config([
        'server.php_dir' => $this->phpDir,
        'server.php_binary_pattern' => '/usr/bin/php{version}',
        'server.runtimes.php.panel_required' => ['curl', 'mbstring'],
    ]);
});

afterEach(fn () => File::deleteDirectory($this->phpDir));

function fakeExtensions(): ArrayObject
{
    $runs = new ArrayObject;
    $soDir = test()->soDir;

    Process::fake(function ($process) use ($runs, $soDir) {
        $runs[] = $process->command;
        $command = $process->command;
        $first = $command[0] ?? '';

        // apt-cache lists more than is installed, including packages that
        // share the prefix but are not extensions at all.
        if ($first === 'apt-cache') {
            $version = ltrim((string) end($command), '^php');
            $version = preg_replace('/-$/', '', (string) preg_replace('/^\^php/', '', (string) end($command)));

            return Process::result(output: collect([
                'curl', 'mysql', 'redis', 'mbstring', 'xdebug', 'imagick', 'opcache',
                'fpm', 'cli', 'common', 'dev', 'phpdbg',
            ])->map(fn ($n) => "php{$version}-{$n} - a php module")->join("\n"));
        }

        if ($first === 'dpkg-query') {
            $owner = [
                'curl' => 'curl', 'mbstring' => 'mbstring', 'redis' => 'redis', 'opcache' => 'opcache',
                // The case the whole design turns on: one package, three modules.
                'mysqli' => 'mysql', 'pdo_mysql' => 'mysql', 'mysqlnd' => 'mysql',
            ];
            $version = preg_match('/php(\d+\.\d+)/', implode(' ', $command), $m) ? $m[1] : test()->panel;

            return Process::result(output: collect($command)
                ->filter(fn ($a) => str_ends_with((string) $a, '.so'))
                ->map(fn ($path) => 'php'.test()->panel.'-'.($owner[basename($path, '.so')] ?? 'unknown').": {$path}")
                ->join("\n"));
        }

        // `php -r 'echo ini_get("extension_dir");'`
        if (in_array('-r', $command, true)) {
            return Process::result(output: $soDir);
        }

        // `php -m` — the loaded set, including things compiled in.
        if (in_array('-m', $command, true)) {
            return Process::result(output: "[PHP Modules]\nCore\ncurl\njson\nmbstring\nmysqli\npcre\nredis\nstandard\n\n[Zend Modules]\nZend OPcache\n");
        }

        return Process::result(exitCode: 0);
    });

    return $runs;
}

function extCall(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->json($method, $uri, $body);
}

function catalogFor(string $version): Collection
{
    return collect(extCall('GET', "/api/settings/php/versions/{$version}/extensions")->json('extensions'))
        ->keyBy('name');
}

it('lists what apt offers, not just what is installed', function () {
    fakeExtensions();

    $catalog = catalogFor($this->panel);

    expect($catalog['xdebug']['installed'])->toBeFalse()
        ->and($catalog['xdebug']['enabled'])->toBeFalse()
        ->and($catalog['redis']['installed'])->toBeTrue()
        ->and($catalog['redis']['enabled'])->toBeTrue();
});

it('drops packages that share the prefix but are not extensions', function () {
    fakeExtensions();

    // php8.4-fpm is a SAPI, php8.4-common is shared config. A toggle for
    // either is a button that breaks the server.
    expect(catalogFor($this->panel)->keys())
        ->not->toContain('fpm', 'cli', 'common', 'dev', 'phpdbg');
});

it('treats one package with several modules as one row', function () {
    fakeExtensions();

    // php8.4-mysql provides mysqli, mysqlnd and pdo_mysql. Three checkboxes
    // that must always move together is not a choice, it is a trap.
    $mysql = catalogFor($this->panel)['mysql'];

    expect($mysql['modules'])->toBe(['mysqli', 'mysqlnd', 'pdo_mysql'])
        ->and($mysql['installed'])->toBeTrue()
        ->and($mysql['enabled'])->toBeTrue();
});

it('calls a package off when only some of its modules are on', function () {
    fakeExtensions();
    File::delete("{$this->phpDir}/{$this->panel}/fpm/conf.d/20-pdo_mysql.ini");

    // Half-enabled behaves like off — a site calling PDO still fails.
    $mysql = catalogFor($this->panel)['mysql'];

    expect($mysql['enabled'])->toBeFalse()
        ->and($mysql['sapis'])->toBe(['cli' => true, 'fpm' => false]);
});

it('lists compiled-in extensions without a control', function () {
    fakeExtensions();

    $catalog = catalogFor($this->panel);

    // In `php -m`, absent from mods-available, removable by nobody.
    expect($catalog['json']['builtin'])->toBeTrue()
        ->and($catalog['json']['package'])->toBeNull()
        ->and($catalog['curl']['builtin'])->toBeFalse();
});

it('refuses to turn off a compiled-in extension', function () {
    fakeExtensions();

    extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/json", ['enabled' => false])
        ->assertUnprocessable();
});

it('reloads fpm after a toggle, because phpenmod does not', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])
        ->assertOk();

    $commands = collect($runs);

    expect($commands)->toContain(['/usr/sbin/phpdismod', '-v', $this->panel, '-s', 'ALL', 'redis'])
        // Without this the toggle flips in the UI and nothing changes on the
        // server until something else happens to restart FPM.
        ->toContain(['systemctl', 'reload', "php{$this->panel}-fpm"]);
});

it('toggles every SAPI at once', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/redis", ['enabled' => true])
        ->assertOk();

    // cli and fpm diverging means a site that works in a browser and fails in
    // a cron deploy, with nothing on screen explaining why.
    expect(collect($runs))->toContain(['/usr/sbin/phpenmod', '-v', $this->panel, '-s', 'ALL', 'redis']);
});

it('queues an install when enabling something not on the box yet', function () {
    Queue::fake();
    fakeExtensions();

    extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/xdebug", ['enabled' => true])
        ->assertStatus(202);

    Queue::assertPushed(InstallPhpExtension::class, 1);
    expect((new InstallPhpExtension('8.4', 'xdebug'))->uniqueId())->toBe('php-ext-8.4-xdebug');
});

it('refuses to disable an extension the panel runs on', function () {
    fakeExtensions();

    // curl is in panel_required. Disabling it under the panel means the
    // request to turn it back on never gets answered.
    extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/curl", ['enabled' => false])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Turning curl off would take the panel offline — it needs curl.']);
});

it('allows disabling that same extension on a version the panel does not use', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/settings/php/versions/{$this->other}/extensions/curl", ['enabled' => false])
        ->assertOk();

    expect(collect($runs))->toContain(['/usr/sbin/phpdismod', '-v', $this->other, '-s', 'ALL', 'curl']);
});

it('refuses an extension this server does not offer', function () {
    fakeExtensions();

    // The name becomes an apt package name and a path, so a pattern is not
    // enough — it has to be something apt actually lists.
    foreach (['nosuchext', 'redis;rm -rf /', '../../etc/passwd'] as $name) {
        extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/".urlencode($name), ['enabled' => true])
            ->assertNotFound();
    }
});

it('404s for a version that is not installed', function () {
    fakeExtensions();

    extCall('GET', '/api/settings/php/versions/5.6/extensions')->assertNotFound();
    extCall('PUT', '/api/settings/php/versions/5.6/extensions/redis', ['enabled' => true])->assertNotFound();
});

it('never purges a package', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/settings/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])->assertOk();

    // Disabling unlinks and stops. `apt purge php8.4-*` is how a server loses
    // php8.4-common and every site with it.
    expect(collect($runs)->filter(fn ($c) => in_array('purge', $c, true)))->toBeEmpty();
});

it('installs with --no-install-recommends and no prompt', function () {
    $runs = fakeExtensions();

    app(PhpExtensionManager::class)->install($this->panel, 'xdebug');

    expect(collect($runs))->toContain(
        ['apt-get', 'install', '-y', '--no-install-recommends', "php{$this->panel}-xdebug"]
    );
});

it('lets a view-only user read the catalog but not change it', function () {
    fakeExtensions();
    $user = User::factory()->create();
    grantPermission($user, 'setting', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/settings/php/versions/{$this->panel}/extensions")->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/settings/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])
        ->assertForbidden();
});

it('does not list OPcache twice under its display name', function () {
    fakeExtensions();
    File::put("{$this->phpDir}/{$this->panel}/mods-available/opcache.ini", "zend_extension=opcache.so\n");
    File::put("{$this->soDir}/opcache.so", '');

    // `php -m` says "Zend OPcache"; the package and the ini say `opcache`.
    // Compared naively it looks like a built-in as well as a package, and the
    // user sees the same extension twice with different controls.
    expect(catalogFor($this->panel)->where('name', 'opcache')->count())->toBe(1)
        ->and(catalogFor($this->panel)->keys())->not->toContain('zend opcache', 'zendopcache');
});
