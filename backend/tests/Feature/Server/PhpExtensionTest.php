<?php

use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Jobs\InstallPhpExtension;
use App\Models\User;
use App\Services\Server\Php\PhpExtensionManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\FakeProcessResult;
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

    // Debian layout in miniature: mods-available = installed, each SAPI's conf.d = switched on.
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

/** @param  (callable(array<int, string>): ?FakeProcessResult)|null  $override */
function fakeExtensions(?callable $override = null, string $startupWarning = ''): ArrayObject
{
    $runs = new ArrayObject;
    $soDir = test()->soDir;

    Process::fake(function ($process) use ($runs, $soDir, $override, $startupWarning) {
        $runs[] = $process->command;

        if ($override !== null && ($result = $override($process->command)) !== null) {
            return $result;
        }
        $command = $process->command;
        $first = $command[0] ?? '';

        // apt-cache lists more than is installed, including prefix-sharing non-extensions.
        if ($first === 'apt-cache') {
            $version = ltrim((string) end($command), '^php');
            $version = preg_replace('/-$/', '', (string) preg_replace('/^\^php/', '', (string) end($command)));

            return Process::result(output: collect([
                'curl', 'mysql', 'redis', 'mbstring', 'xdebug', 'imagick', 'opcache', 'ioncube',
                'fpm', 'cli', 'common', 'dev', 'phpdbg',
            ])->map(fn ($n) => "php{$version}-{$n} - a php module")->join("\n"));
        }

        if ($first === 'dpkg-query') {
            $owner = [
                'curl' => 'curl', 'mbstring' => 'mbstring', 'redis' => 'redis', 'opcache' => 'opcache', 'xdebug' => 'xdebug',
                // The case the whole design turns on: one package, three modules.
                'mysqli' => 'mysql', 'pdo_mysql' => 'mysql', 'mysqlnd' => 'mysql',
            ];
            $version = preg_match('/php(\d+\.\d+)/', implode(' ', $command), $m) ? $m[1] : test()->panel;

            return Process::result(output: collect($command)
                ->filter(fn ($a) => str_ends_with((string) $a, '.so'))
                ->map(fn ($path) => 'php'.test()->panel.'-'.($owner[basename($path, '.so')] ?? 'unknown').": {$path}")
                ->join("\n"));
        }

        // apt puts the package's .so and mods-available ini in place. Match the package, not
        // the last argument: apt() appends its lock option.
        if ($first === 'apt-get' && in_array('install', $command, true)
            && preg_match('/^php(\d+\.\d+)-([a-z0-9_]+)$/', (string) collect($command)->first(fn ($arg) => str_starts_with((string) $arg, 'php')), $m)) {
            File::put(test()->phpDir."/{$m[1]}/mods-available/{$m[2]}.ini", "extension={$m[2]}.so\n");
            File::put("{$soDir}/{$m[2]}.so", '');

            return Process::result(exitCode: 0);
        }

        // phpenmod / phpdismod as Debian's behave: each module with an ini in mods-available
        // gets its conf.d link added or removed; a name with no ini is skipped, exit 0.
        if (in_array(basename($first), ['phpenmod', 'phpdismod'], true)) {
            $version = $command[array_search('-v', $command, true) + 1];
            $dir = test()->phpDir."/{$version}";

            foreach (array_slice($command, array_search('ALL', $command, true) + 1) as $module) {
                if (! File::exists("{$dir}/mods-available/{$module}.ini")) {
                    continue;
                }

                foreach (['cli', 'fpm'] as $sapi) {
                    basename($first) === 'phpenmod'
                        ? File::put("{$dir}/{$sapi}/conf.d/20-{$module}.ini", '')
                        : File::delete("{$dir}/{$sapi}/conf.d/20-{$module}.ini");
                }
            }

            return Process::result(errorOutput: '');
        }

        // `php -r 'echo ini_get("extension_dir");'`
        if (in_array('-r', $command, true)) {
            return Process::result(output: $startupWarning.$soDir);
        }

        // `php -m` — the loaded set, including things compiled in.
        if (in_array('-m', $command, true)) {
            return Process::result(output: "[PHP Modules]\nCore\ncurl\nionCube Loader\njson\nmbstring\nmysqli\npcre\nredis\nstandard\n\n[Zend Modules]\nthe ionCube PHP Loader\nZend OPcache\n");
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
    return collect(extCall('GET', "/api/php/versions/{$version}/extensions")->json('extensions'))
        ->keyBy('name');
}

it('still finds installed extensions when PHP prints a startup warning first', function () {
    fakeExtensions(startupWarning: "PHP Warning:  Module \"redis\" is already loaded in Unknown on line 0\n");

    // One package, three modules: only readable from the extension dir.
    $catalog = catalogFor($this->panel);

    expect($catalog['mysql']['installed'])->toBeTrue()
        ->and($catalog['mysql']['modules'])->toContain('mysqli');
});

it('lists what apt offers, not just what is installed', function () {
    fakeExtensions();

    $catalog = catalogFor($this->panel);

    expect($catalog['xdebug']['installed'])->toBeFalse()
        ->and($catalog['xdebug']['enabled'])->toBeFalse()
        ->and($catalog['redis']['installed'])->toBeTrue()
        ->and($catalog['redis']['enabled'])->toBeTrue();
});

// Rows show apt's own one-liner from `apt-cache search`.
it('carries apt\'s description for each package, and none for a built-in', function () {
    fakeExtensions();

    $catalog = catalogFor($this->panel);

    expect($catalog['xdebug']['summary'])->toBe('a php module')
        ->and($catalog['redis']['summary'])->toBe('a php module')
        ->and($catalog['json']['summary'])->toBeNull();
});

it('drops packages that share the prefix but are not extensions', function () {
    fakeExtensions();

    // php8.4-fpm is a SAPI, php8.4-common is shared config: a toggle for either breaks the server.
    expect(catalogFor($this->panel)->keys())
        ->not->toContain('fpm', 'cli', 'common', 'dev', 'phpdbg');
});

it('treats one package with several modules as one row', function () {
    fakeExtensions();

    // php8.4-mysql provides mysqli, mysqlnd and pdo_mysql; they must always move together.
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

it('leaves ionCube to its own card', function () {
    // On OpenLiteSpeed `php -m` lists "ionCube Loader" (a bogus built-in row) and the repository
    // offers `lsphp84-ioncube` (a second loader beside the ionCube card's).
    fakeExtensions();

    $names = collect(extCall('GET', "/api/php/versions/{$this->panel}/extensions")->json('extensions'))->pluck('name');

    expect($names->filter(fn (string $n) => str_contains(strtolower($n), 'ioncube')))->toBeEmpty();
});

// LiteSpeed's PECL packages number their ini (`50-redis.ini`). Read as-is the module was
// `50-redis`: the package showed "not installed" and a second built-in `redis` row appeared.
it('reads a numbered ini in mods-available as the module it loads', function () {
    File::move(
        "{$this->phpDir}/{$this->panel}/mods-available/redis.ini",
        "{$this->phpDir}/{$this->panel}/mods-available/50-redis.ini",
    );
    fakeExtensions();

    $rows = collect(extCall('GET', "/api/php/versions/{$this->panel}/extensions")->json('extensions'));
    $redis = $rows->where('name', 'redis');

    expect($redis)->toHaveCount(1)
        ->and($redis->first()['installed'])->toBeTrue()
        ->and($redis->first()['builtin'])->toBeFalse()
        ->and($rows->pluck('name'))->not->toContain('50-redis');
});

it('says whether installed extensions can be switched off', function () {
    fakeExtensions();

    extCall('GET', "/api/php/versions/{$this->panel}/extensions")
        ->assertOk()
        ->assertJsonPath('toggle_supported', true);
});

it('refuses to turn off a compiled-in extension', function () {
    fakeExtensions();

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/json", ['enabled' => false])
        ->assertUnprocessable();
});

it('reloads fpm after a toggle, because phpenmod does not', function () {
    $runs = fakeExtensions();

    // Not the panel's own version: the panel's cache and queue run on redis.
    extCall('PUT', "/api/php/versions/{$this->other}/extensions/redis", ['enabled' => false])
        ->assertOk();

    $commands = collect($runs);

    expect($commands)->toContain(['/usr/sbin/phpdismod', '-v', $this->other, '-s', 'ALL', 'redis'])
        // Without the reload the toggle flips in the UI but FPM keeps running as before.
        ->toContain(['systemctl', 'reload', "php{$this->other}-fpm"]);
});

it('toggles every SAPI at once', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/redis", ['enabled' => true])
        ->assertOk();

    // cli and fpm diverging means a site that works in a browser and fails in a cron deploy.
    expect(collect($runs))->toContain(['/usr/sbin/phpenmod', '-v', $this->panel, '-s', 'ALL', 'redis']);
});

it('queues an install when enabling something not on the box yet', function () {
    Queue::fake();
    fakeExtensions();

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/xdebug", ['enabled' => true])
        ->assertStatus(202);

    Queue::assertPushed(InstallPhpExtension::class, 1);
    expect((new InstallPhpExtension('8.4', 'xdebug'))->uniqueId())->toBe('php-ext-8.4-xdebug');
});

it('refuses to disable an extension the panel runs on', function () {
    fakeExtensions();

    // curl is in panel_required: with it off the request to turn it back on is never answered.
    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/curl", ['enabled' => false])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Turning curl off would take the panel offline — it needs curl.']);
});

// The panel's cache, queue and sessions run on phpredis: turning it off takes the panel down.
it('refuses to disable redis under the panel when the panel uses phpredis', function () {
    fakeExtensions();
    config(['database.redis.client' => 'phpredis']);

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Turning redis off would take the panel offline — it needs redis.']);

    extCall('GET', "/api/php/versions/{$this->panel}/extensions")
        ->assertOk()
        ->assertJsonPath('panel_required', fn (array $list) => in_array('redis', $list, true) && in_array('igbinary', $list, true));
});

it('lets redis go when the panel does not use phpredis', function () {
    fakeExtensions();
    config(['database.redis.client' => 'predis']);

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])->assertOk();
});

it('protects the driver of the database the panel is on', function () {
    $manager = app(PhpExtensionManager::class);
    config(['server.runtimes.php.panel_required' => [], 'database.redis.client' => 'predis']);

    // Flipped only around the call: the test itself is on sqlite.
    $default = config('database.default');
    config(['database.default' => 'mysql']);
    $required = $manager->panelRequired();
    config(['database.default' => $default]);

    expect($required)->toBe(['pdo_mysql', 'mysqlnd']);
});

// phpdismod handed a package name finds no ini by it and exits 0, so the modules stayed loaded.

it('turns off every module of a package, not just its name', function () {
    $runs = fakeExtensions();
    config(['database.redis.client' => 'predis']);

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/mysql", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('extension.enabled', false);

    expect(collect($runs))->toContain(['/usr/sbin/phpdismod', '-v', $this->panel, '-s', 'ALL', 'mysqli', 'mysqlnd', 'pdo_mysql'])
        ->and(File::exists("{$this->phpDir}/{$this->panel}/fpm/conf.d/20-mysqli.ini"))->toBeFalse()
        ->and(File::exists("{$this->phpDir}/{$this->panel}/fpm/conf.d/20-pdo_mysql.ini"))->toBeFalse();
});

it('says so, and logs nothing, when the extension did not actually move', function () {
    // Exits 0 and changes nothing, as phpdismod does for a name it has no ini for.
    fakeExtensions(fn (array $command) => str_ends_with((string) $command[0], 'phpdismod') ? Process::result() : null);
    config(['database.redis.client' => 'predis']);

    extCall('PUT', "/api/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('errors/php.extension_not_disabled', ['extension' => 'redis', 'version' => $this->panel]));

    $this->assertDatabaseMissing('activity_logs', ['type' => 'php', 'action' => 'extension_disabled']);
});

it('allows disabling that same extension on a version the panel does not use', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/php/versions/{$this->other}/extensions/curl", ['enabled' => false])
        ->assertOk();

    expect(collect($runs))->toContain(['/usr/sbin/phpdismod', '-v', $this->other, '-s', 'ALL', 'curl']);
});

it('refuses an extension this server does not offer', function () {
    fakeExtensions();

    // The name becomes an apt package name and a path: it must be something apt actually lists.
    foreach (['nosuchext', 'redis;rm -rf /', '../../etc/passwd'] as $name) {
        extCall('PUT', "/api/php/versions/{$this->panel}/extensions/".urlencode($name), ['enabled' => true])
            ->assertNotFound();
    }
});

it('404s for a version that is not installed', function () {
    fakeExtensions();

    extCall('GET', '/api/php/versions/5.6/extensions')->assertNotFound();
    extCall('PUT', '/api/php/versions/5.6/extensions/redis', ['enabled' => true])->assertNotFound();
});

it('never purges a package', function () {
    $runs = fakeExtensions();

    extCall('PUT', "/api/php/versions/{$this->other}/extensions/redis", ['enabled' => false])->assertOk();

    // `apt purge php8.4-*` would remove php8.4-common and every site with it.
    expect(collect($runs)->filter(fn ($c) => in_array('purge', $c, true)))->toBeEmpty();
});

// A failed reload must surface: otherwise the screen says on while workers run without it.
it('reports a failed reload after a toggle instead of success', function () {
    fakeExtensions(fn (array $command) => ($command[0] ?? '') === 'systemctl'
        ? Process::result(exitCode: 1, errorOutput: 'Job for php-fpm failed')
        : null);

    extCall('PUT', "/api/php/versions/{$this->other}/extensions/redis", ['enabled' => false])
        ->assertStatus(500)
        ->assertJsonPath('message', __('errors/php.reload_failed', ['version' => $this->other]))
        ->assertJsonStructure(['reference']);

    $this->assertDatabaseMissing('activity_logs', ['action' => 'extension_disabled']);
});

it('records an install whose reload failed as reload_failed, not enable_failed', function () {
    // `enable_failed` tells the user to press the toggle again, which is no fix here.
    fakeExtensions(fn (array $command) => ($command[0] ?? '') === 'systemctl' ? Process::result(exitCode: 1) : null);

    try {
        app(PhpExtensionManager::class)->install($this->other, 'xdebug');
        $this->fail('expected the install to fail');
    } catch (RuntimeInstallException $e) {
        expect($e->reason)->toBe('reload_failed');
    }
});

it('installs with --no-install-recommends and no prompt', function () {
    $runs = fakeExtensions();

    app(PhpExtensionManager::class)->install($this->panel, 'xdebug');

    expect(collect($runs))->toContain(
        aptCommand('install', '-y', '--no-install-recommends', "php{$this->panel}-xdebug")
    );
});

it('lets a view-only user read the catalog but not change it', function () {
    fakeExtensions();
    $user = User::factory()->create();
    grantPermission($user, 'php', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/php/versions/{$this->panel}/extensions")->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/php/versions/{$this->panel}/extensions/redis", ['enabled' => false])
        ->assertForbidden();
});

it('does not list OPcache twice under its display name', function () {
    fakeExtensions();
    File::put("{$this->phpDir}/{$this->panel}/mods-available/opcache.ini", "zend_extension=opcache.so\n");
    File::put("{$this->soDir}/opcache.so", '');

    // `php -m` says "Zend OPcache"; the package and ini say `opcache`. Compared naively it
    // shows twice, as a built-in and as a package.
    expect(catalogFor($this->panel)->where('name', 'opcache')->count())->toBe(1)
        ->and(catalogFor($this->panel)->keys())->not->toContain('zend opcache', 'zendopcache');
});
