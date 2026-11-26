<?php

use App\Contracts\PhpStack;
use App\Enums\InstallStatus;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Jobs\InstallPhpVersion;
use App\Jobs\RemovePhpVersion;
use App\Models\Application;
use App\Models\RuntimeInstall;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\IonCubeLoader;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\Runtimes\PhpRuntime;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A fake /etc/php, so the test never depends on what this machine has.
    $this->phpDir = sys_get_temp_dir().'/sv-oss-phprt-'.getmypid();
    File::deleteDirectory($this->phpDir);
    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->phpDir}/{$version}/fpm", 0755, true);
    }

    config([
        'server.php_dir' => $this->phpDir,
        'server.php_binary_pattern' => '/usr/bin/php{version}',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->phpDir));

function fakePhp(string $default = '8.4', bool $ok = true, array $absent = [], bool $bare = false, ?string $search = null, array $candidates = []): ArrayObject
{
    $runs = new ArrayObject;
    $search ??= "php8.2-fpm - server-side scripting\nphp8.3-fpm - server-side scripting\nphp8.4-fpm - server-side scripting\n";

    Process::fake(function ($process) use ($runs, $default, $ok, $absent, $bare, $search, $candidates) {
        $runs[] = ['command' => $process->command, 'env' => $process->environment ?? []];
        $command = $process->command;

        // `$ok: false` fails the mutating commands only; the reads must still answer.
        if (! $ok && ($command[0] ?? '') === 'apt-get') {
            return Process::result(exitCode: 1, errorOutput: 'E: Could not get lock');
        }

        return match (true) {
            ($command[0] ?? '') === 'update-alternatives' && in_array('--query', $command, true) => Process::result(
                output: "Name: php\nLink: /usr/bin/php\nStatus: auto\nBest: /usr/bin/php{$default}\nValue: /usr/bin/php{$default}\n"
            ),
            // `apt-cache policy <pkg>`: the availability check install() makes before calling apt.
            // An unknown package prints NOTHING and still exits 0; a known-but-uninstallable one
            // prints `Candidate: (none)`. One block per package, as apt does. `$absent` models
            // names the index does not have.
            ($command[0] ?? '') === 'apt-cache' && ($command[1] ?? '') === 'policy' => Process::result(
                output: collect(array_slice($command, 2))
                    ->reject(fn (string $package) => in_array($package, $absent, true))
                    ->map(fn (string $package) => "{$package}:\n  Installed: (none)\n  Candidate: ".($candidates[$package] ?? '1.0')."\n  Version table:\n")
                    ->implode('')
            ),
            ($command[0] ?? '') === 'apt-cache' => Process::result(output: $search),
            // `dpkg-query -W`: which base packages are on the box. The default has all of them;
            // `$bare` models a version that arrived as another package's dependency with only the
            // interpreter. An unknown name exits non-zero, which is an ordinary answer, not a failure.
            ($command[0] ?? '') === 'dpkg-query' && ($command[1] ?? '') === '-W' => Process::result(
                output: $bare
                    ? ''
                    : collect(array_slice($command, 3))
                        ->map(fn (string $package) => "{$package} install ok installed")
                        ->implode("\n")."\n",
                exitCode: $bare ? 1 : 0,
            ),
            default => Process::result(exitCode: 0),
        };
    });

    return $runs;
}

function phpSettings(): array
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/php')->json('php');
}

function phpCall(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->json($method, $uri, $body);
}

it('reads the default from update-alternatives, which is what owns /usr/bin/php', function () {
    fakePhp(default: '8.3');

    // Managing that symlink by hand would fight the package manager.
    expect(phpSettings()['default'])->toBe('8.3');
});

it('lists installed versions from the same place the Services screen reads', function () {
    fakePhp();

    $versions = collect(phpSettings()['versions'])->keyBy('version');

    // One source, so the two screens cannot disagree about what exists.
    expect($versions->keys()->all())->toBe(['8.4', '8.3'])
        ->and($versions['8.4']['path'])->toBe('/usr/bin/php8.4')
        ->and($versions['8.4']['is_default'])->toBeTrue();
});

it('orders pinned site names without case bias', function () {
    fakePhp();

    $user = SystemUser::create([
        'username' => 'mixedphp',
        'home_path' => '/home/mixedphp',
        'shell' => '/bin/bash',
        'sudo' => false,
    ]);

    foreach ([
        ['name' => 'Case Zebra', 'slug' => 'case-zebra', 'domain' => 'zebra.test'],
        ['name' => 'case apple', 'slug' => 'case-apple', 'domain' => 'apple.test'],
        ['name' => 'CASE Banana', 'slug' => 'case-banana', 'domain' => 'banana.test'],
    ] as $site) {
        Application::forceCreate($site + [
            'system_user_id' => $user->id,
            'site_type' => 'php',
            'serving_profile' => 'php',
            'web_root' => '/',
            'status' => 'pending',
            'php_version' => '8.3',
        ]);
    }

    $version = collect(phpSettings()['versions'])->firstWhere('version', '8.3');

    expect($version['sites'])->toBe(['case apple', 'CASE Banana', 'Case Zebra']);
});

it('offers only versions that are not installed yet', function () {
    fakePhp();

    // apt-cache lists 8.2, 8.3 and 8.4; the last two are already here.
    expect(collect(phpSettings()['installable'])->pluck('version')->all())->toBe(['8.2']);
});

it('does not offer a PHP whose candidate is a pre-release', function () {
    // A `php8.6-fpm` whose apt candidate is a beta must not look like any other release.
    fakePhp(
        search: "php8.2-fpm - x\nphp8.3-fpm - x\nphp8.4-fpm - x\nphp8.5-fpm - x\nphp8.6-fpm - x\n",
        candidates: [
            'php8.5-fpm' => '8.5.11-1+0~20260924.26+ubuntu26.04~1.gbpbcb504',
            'php8.6-fpm' => '8.6.0~beta3-1+0~20260923.2+ubuntu26.04~1.gbp664ce9',
        ],
    );

    // 8.5's candidate also has `~` in it (the build date): only the pre-release
    // marker counts, not the character.
    expect(collect(phpSettings()['installable'])->pluck('version')->all())->toBe(['8.5', '8.2']);
});

it('offers a pre-release when told to', function () {
    config(['server.runtimes.php.offer_prerelease' => true]);

    fakePhp(
        search: "php8.5-fpm - x\nphp8.6-fpm - x\n",
        candidates: ['php8.6-fpm' => '8.6.0~RC1-1'],
    );

    expect(collect(phpSettings()['installable'])->pluck('version')->all())->toBe(['8.6', '8.5']);
});

it('offers nothing older than the configured floor for a new install', function () {
    // 5.6 to 7.3 are long past end of life and are not offered, though the repository lists them.
    fakePhp(search: "php5.6-fpm - x\nphp7.0-fpm - x\nphp7.3-fpm - x\nphp7.4-fpm - x\nphp8.2-fpm - x\n");

    expect(collect(phpSettings()['installable'])->pluck('version')->all())->toBe(['8.2', '7.4']);

    // Refused as "below the minimum", not as unknown: 7.3 is in the repository.
    phpCall('POST', '/api/php/versions', ['version' => '7.3'])
        ->assertStatus(422)
        ->assertJsonPath('errors.version.0', __('php.below_minimum', ['version' => '7.3', 'minimum' => '7.4']));
});

it('still lists and keeps an old version that is already installed', function () {
    // A server already running 7.2 must not lose it from the screen when the panel stops offering it.
    File::makeDirectory("{$this->phpDir}/7.2/fpm", 0755, true);
    fakePhp(search: "php7.2-fpm - x\nphp8.2-fpm - x\n");

    $settings = phpSettings();

    expect(collect($settings['versions'])->pluck('version')->all())->toContain('7.2')
        ->and(collect($settings['installable'])->pluck('version')->all())->toBe(['8.2']);
});

it('offers an old version again when the floor is lowered on purpose', function () {
    config(['server.runtimes.php.min_offered' => '5.6']);
    fakePhp(search: "php5.6-fpm - x\nphp8.2-fpm - x\n");

    expect(collect(phpSettings()['installable'])->pluck('version')->all())->toBe(['8.2', '5.6']);
});

it('refuses to install a pre-release through the API', function () {
    fakePhp(
        search: "php8.5-fpm - x\nphp8.6-fpm - x\n",
        candidates: ['php8.6-fpm' => '8.6.0~beta3-1'],
    );

    phpCall('POST', '/api/php/versions', ['version' => '8.6'])->assertStatus(422);
});

it('marks the version the panel itself runs on', function () {
    fakePhp();

    $panel = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $versions = collect(phpSettings()['versions'])->keyBy('version');

    expect($versions[$panel]['in_use_by_panel'])->toBeTrue()
        ->and($versions->except($panel)->every(fn ($v) => $v['in_use_by_panel'] === false))->toBeTrue();
});

it('refuses to remove the version the panel is running on', function () {
    fakePhp(default: '8.3');
    $panel = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

    // Removing it would take the panel offline from inside the panel, with no way back.
    phpCall('DELETE', "/api/php/versions/{$panel}")
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => "Removing PHP {$panel} would take the panel offline — it is the version the panel itself runs on."]);
});

it('refuses to remove a version a site is pinned to, naming the site', function () {
    fakePhp(default: '8.4');

    $user = SystemUser::create(['username' => 'p', 'home_path' => '/home/p', 'shell' => '/bin/bash', 'sudo' => false]);
    Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Legacy shop',
        'slug' => 'legacy-shop', 'domain' => 'l.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'web_root' => '/',
        'status' => 'pending', 'php_version' => '8.3',
    ]);

    phpCall('DELETE', '/api/php/versions/8.3')
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'PHP 8.3 is used by Legacy shop. Change those applications first.']);
});

it('queues the removal instead of purging inside the request', function () {
    Queue::fake();
    fakePhp(default: '8.4');

    // 202, not 204: apt takes minutes and nginx ends a request at fastcgi_read_timeout.
    phpCall('DELETE', '/api/php/versions/8.3')->assertStatus(202);

    Queue::assertPushed(RemovePhpVersion::class, fn ($job) => $job->version === '8.3');
});

it('marks the version as removing before the worker picks it up', function () {
    Queue::fake();
    fakePhp(default: '8.4');

    phpCall('DELETE', '/api/php/versions/8.3')->assertStatus(202);

    // Recorded before dispatch: a client reloading right after the 202 must see it marked.
    expect(RuntimeInstall::where('version', '8.3')->first()?->status)
        ->toBe(InstallStatus::Removing);
});

it('purges and then clears what the purge cannot', function () {
    // The per-site pool files in <version>/fpm/pool.d are not dpkg's, so the directory survives
    // the purge, and detection reads exactly these directories.
    $runs = fakePhp(default: '8.4');

    app(PhpRuntime::class)->uninstall('8.3');

    expect(collect($runs)->pluck('command'))
        ->toContain(aptCommand('purge', '-y', 'php8.3-*'))
        ->toContain(['rm', '-rf', config('server.php_dir').'/8.3']);
});

/*
 * The panel's ionCube ini and loader are not dpkg's, so the purge leaves them; a reinstall of
 * that version would load ionCube again unannounced.
 */
it('deletes the panel\'s ionCube files once the purge has succeeded', function () {
    $ionCube = Mockery::mock(IonCubeLoader::class);
    $ionCube->shouldReceive('panelFiles')->with('8.3')->andReturn(['/scan/01-ioncube.ini', '/ext/ioncube_loader_lin_8.3.so']);
    app()->instance(IonCubeLoader::class, $ionCube);

    $runs = fakePhp(default: '8.4');

    app(PhpRuntime::class)->uninstall('8.3');

    $commands = collect($runs)->pluck('command')->values();
    $purge = $commands->search(['apt-get', 'purge', '-y', 'php8.3-*']);

    expect($commands)
        ->toContain(['rm', '-f', '/scan/01-ioncube.ini'])
        ->toContain(['rm', '-f', '/ext/ioncube_loader_lin_8.3.so'])
        ->and($commands->search(['rm', '-f', '/ext/ioncube_loader_lin_8.3.so']))->toBeGreaterThan($purge);
});

it('leaves ionCube alone when the purge failed', function () {
    config(['server.apt.lock_attempts' => 2, 'server.apt.lock_delay_ms' => 0]);
    $ionCube = Mockery::mock(IonCubeLoader::class);
    $ionCube->shouldReceive('panelFiles')->andReturn(['/ext/ioncube_loader_lin_8.3.so']);
    app()->instance(IonCubeLoader::class, $ionCube);

    $runs = fakePhp(default: '8.4', ok: false);

    expect(fn () => app(PhpRuntime::class)->uninstall('8.3'))->toThrow(SettingOperationException::class);
    expect(collect($runs)->pluck('command'))->not->toContain(['rm', '-f', '/ext/ioncube_loader_lin_8.3.so']);
});

it('does not clear the directory when the purge failed', function () {
    // Stripping the configuration after apt refused to remove the version would leave a working
    // PHP with no config at all.
    // "E: Could not get lock" is a transient failure that ServerOps::apt() retries on the real
    // budget (40 x 15 s), so the retry config is shrunk; ServerOpsRetryTest covers the budget.
    config(['server.apt.lock_attempts' => 2, 'server.apt.lock_delay_ms' => 0]);

    $runs = fakePhp(default: '8.4', ok: false);

    expect(fn () => app(PhpRuntime::class)->uninstall('8.3'))
        ->toThrow(SettingOperationException::class);

    expect(collect($runs)->pluck('command'))
        ->not->toContain(['rm', '-rf', config('server.php_dir').'/8.3']);
});

it('installs a usable PHP, not a bare interpreter', function () {
    // A bare php8.2-fpm has no mysql, no curl, no mbstring: marketplace applications would fail.
    // Uses fakePhp() because it answers `apt-cache policy`; a stub answering nothing reads as
    // "the index has none of these".
    $runs = fakePhp();

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');
    expect($install)->toContain('php8.2-fpm', 'php8.2-mysql', 'php8.2-curl', 'php8.2-mbstring');
});

it('drops a package this server cannot install, and keeps the rest', function () {
    // apt fails the WHOLE transaction on one unknown name (`E: Unable to locate package ...`),
    // so a single gap in a version's package set would install nothing at all.
    $runs = fakePhp(absent: ['php8.2-mbstring']);

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');

    expect($install)->not->toContain('php8.2-mbstring')
        // ...and the ones that DO exist still get installed.
        ->and($install)->toContain('php8.2-fpm', 'php8.2-mysql', 'php8.2-curl');
});

it('never drops the interpreter itself', function () {
    // Filtering the interpreter would turn "this version is not available" into a successful
    // install of nothing. Extensions are degradable; the interpreter is not.
    $runs = fakePhp(absent: ['php8.2-fpm', 'php8.2-cli', 'php8.2-common', 'php8.2-mysql']);

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');

    // Still handed to apt, so apt refuses out loud instead of the panel installing an empty set.
    expect($install)->toContain('php8.2-fpm');
});

it('installs the full set when the availability check itself fails', function () {
    // A filter that cannot see must not filter: reading "cannot confirm" (lock held, index
    // broken) as "not there" would strip every extension and report success. Installing the
    // full set puts apt back in charge, and it fails loudly if a package is really missing.
    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command];

        return ($process->command[0] ?? '') === 'apt-cache'
            ? Process::result(exitCode: 100, errorOutput: 'E: Could not open cache file')
            : Process::result(exitCode: 0);
    });

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');
    expect($install)->toContain('php8.2-fpm', 'php8.2-mysql', 'php8.2-curl', 'php8.2-mbstring');
});

it('runs apt unattended, or it waits for a prompt nobody will answer', function () {
    $runs = fakePhp();

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'apt-get' && ($run['command'][1] ?? '') === 'install');
    expect($install['env'])->toBe(['DEBIAN_FRONTEND' => 'noninteractive']);
});

it('queues the install, once per version', function () {
    Queue::fake();
    fakePhp();

    phpCall('POST', '/api/php/versions', ['version' => '8.2'])->assertStatus(202);

    // apt takes a lock; a second run for the same version would wait, then repeat the work.
    Queue::assertPushed(InstallPhpVersion::class, 1);
    expect((new InstallPhpVersion('8.2'))->uniqueId())->toBe('php-install-8.2');
});

it('treats installing a version that is already here as done', function () {
    Queue::fake();
    fakePhp();

    phpCall('POST', '/api/php/versions', ['version' => '8.4'])->assertOk();
    Queue::assertNothingPushed();
});

it('rejects a version that is not major.minor', function () {
    fakePhp();

    // It becomes a package name and a path.
    foreach (['8', '8.4.1', '8.4; rm -rf /'] as $bad) {
        phpCall('POST', '/api/php/versions', ['version' => $bad])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
    }
});

it('changes the default through update-alternatives', function () {
    $runs = fakePhp(default: '8.4');

    phpCall('PUT', '/api/php/default', ['default' => '8.3'])->assertOk();

    expect(collect($runs)->pluck('command'))
        ->toContain(['update-alternatives', '--set', 'php', '/usr/bin/php8.3']);
});

it('moves phar with php, so the two cannot disagree', function () {
    // `php8.x-cli` registers three alternative groups; moving only one makes `php -v` and
    // `phar -v` report different versions.
    $runs = fakePhp(default: '8.4');

    phpCall('PUT', '/api/php/default', ['default' => '8.3'])->assertOk();

    expect(collect($runs)->pluck('command'))
        ->toContain(['update-alternatives', '--set', 'phar', '/usr/bin/phar8.3'])
        ->toContain(['update-alternatives', '--set', 'phar.phar', '/usr/bin/phar.phar8.3']);
});

it('still changes the default when the box has no phar alternative', function () {
    // A box can have the interpreter without the phar links; refusing the change over that
    // would report a change that did happen as one that did not.
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;
        $command = $process->command;

        if (($command[0] ?? '') === 'update-alternatives' && in_array('--query', $command, true)) {
            return Process::result(output: "Name: php\nValue: /usr/bin/php8.4\n");
        }

        // Only the phar groups are missing.
        if (($command[0] ?? '') === 'update-alternatives' && in_array('--set', $command, true)) {
            return str_contains($command[2] ?? '', 'phar')
                ? Process::result(exitCode: 2, errorOutput: 'update-alternatives: error: no alternatives for phar')
                : Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });

    app(PhpRuntime::class)->setDefault('8.3');

    expect(collect($runs))->toContain(['update-alternatives', '--set', 'php', '/usr/bin/php8.3']);
});

it('refuses a default that is not installed', function () {
    fakePhp();

    phpCall('PUT', '/api/php/default', ['default' => '8.1'])->assertUnprocessable();
});

it('denies every mutation to a view-only user', function () {
    fakePhp();
    $user = User::factory()->create();
    grantPermission($user, 'php', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/php')->assertOk();

    foreach ([
        ['PUT', '/api/php/default', ['default' => '8.3']],
        ['POST', '/api/php/versions', ['version' => '8.2']],
        ['DELETE', '/api/php/versions/8.3', []],
    ] as [$method, $uri, $body]) {
        $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $body)->assertForbidden();
    }
});

it('refreshes the package index before asking what exists', function () {
    // `installablePackages()` asks `apt-cache policy`, which reads the LOCAL index. If that
    // predates the repository, every extension answers "no", every one is skipped, and a bare
    // interpreter installs while reporting success.
    // Ordering is the assertion: a refresh after the checks comes too late.
    $runs = fakePhp();

    app(PhpRuntime::class)->install('8.2');

    $commands = collect($runs)->pluck('command')->values();
    $refresh = $commands->search(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'update');
    $firstCheck = $commands->search(fn ($c) => ($c[0] ?? '') === 'apt-cache' && ($c[1] ?? '') === 'policy');

    expect($refresh)->not->toBeFalse()
        ->and($firstCheck)->not->toBeFalse()
        ->and($refresh)->toBeLessThan($firstCheck);
});

it('installs anyway when the index refresh fails', function () {
    // `apt-get update` exits non-zero when ANY source fails, even one unrelated to PHP; refusing
    // to install over that is worse than the stale index the refresh guards against.
    config(['server.apt.lock_attempts' => 2, 'server.apt.lock_delay_ms' => 0]);

    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $command = $process->command;
        $runs[] = ['command' => $command, 'env' => $process->environment ?? []];

        return match (true) {
            ($command[0] ?? '') === 'apt-get' && ($command[1] ?? '') === 'update' => Process::result(
                exitCode: 1, errorOutput: 'E: Failed to fetch https://example.invalid 404',
            ),
            ($command[0] ?? '') === 'apt-cache' => Process::result(output: "  Candidate: 1.0\n"),
            default => Process::result(exitCode: 0),
        };
    });

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')
        ->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');

    expect($install)->not->toBeNull()->and($install)->toContain('php8.2-fpm');
});

/*
| The FPM package list, against install.sh's.
|
| The two lists must not drift: without `sqlite3` a PHP added from the PHP screen has no
| pdo_sqlite, and as the system default it breaks `artisan` on the panel's own SQLite database.
| `OlsInstallerTest` asserts the same parity for OpenLiteSpeed.
|
| install.sh is the source of truth (the set proven on hardware); change both lists together.
*/

it('installs from the panel exactly what install.sh installs', function () {
    $path = base_path('../install.sh');

    if (! is_file($path)) {
        test()->markTestSkipped('install.sh is not in this checkout');
    }

    // The FPM branch only: `install_ols_packages` builds `${lsphp}-` names (OlsInstallerTest).
    preg_match_all('/php\$\{PHP_VERSION\}-([a-z0-9]+)/', (string) file_get_contents($path), $matches);

    $fromInstaller = array_values(array_unique($matches[1]));
    $fromConfig = (array) config('server.runtimes.php.base_packages');

    expect($fromInstaller)->not->toBeEmpty('install.sh installs no php packages?');

    sort($fromInstaller);
    sort($fromConfig);

    expect($fromConfig)->toBe($fromInstaller);
});

it('always installs the driver for the panel\'s own database', function () {
    // Named on its own: the panel stores itself in SQLite, so a version without sqlite3 breaks
    // `artisan` (migrations, scheduler, queue) once it becomes the default.
    expect((array) config('server.runtimes.php.base_packages'))->toContain('sqlite3');
});

/*
| A version the panel did not install.
|
| The `openlitespeed` package pulls in `lsphp83` as its own dependency: a bare interpreter that
| the version picker shows like any other, and a site put on it fails with "curl is not
| installed". `store()` must not read "interpreter exists" as "installed". FPM has the same shape
| with a hand-installed php8.1-fpm.
*/

it('reports the base packages a half-installed version is missing', function () {
    $runs = fakePhp(bare: true);

    $missing = app(PhpRuntime::class)->missingBasePackages('8.3');

    expect($missing)->not->toBeEmpty()
        ->and($missing)->toContain('php8.3-curl')
        ->and($missing)->toContain('php8.3-sqlite3')
        // Never the interpreter: its presence is what "installed" means.
        ->and($missing)->not->toContain('php8.3-fpm')
        ->and(collect($runs)->pluck('command')->contains(fn ($c) => ($c[0] ?? '') === 'dpkg-query'))->toBeTrue();
});

it('reports nothing missing for a version the panel installed', function () {
    fakePhp();

    expect(app(PhpRuntime::class)->missingBasePackages('8.4'))->toBe([]);
});

it('never reports a package the index does not have', function () {
    // LiteSpeed compiles mbstring, xml, zip, gd, bcmath and soap in, so those package names
    // exist nowhere; calling them "missing" would be false.
    fakePhp(bare: true, absent: ['php8.3-mbstring', 'php8.3-soap']);

    $missing = app(PhpRuntime::class)->missingBasePackages('8.3');

    expect($missing)->not->toContain('php8.3-mbstring')
        ->and($missing)->not->toContain('php8.3-soap')
        ->and($missing)->toContain('php8.3-curl');
});

it('completes a half-installed version instead of calling it done', function () {
    // "Install PHP 8.3" must not answer "already installed" for a bare interpreter; apt is
    // idempotent, so falling through to the install is safe.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    fakePhp(bare: true);

    $this->actingAs($admin)
        ->postJson('/api/php/versions', ['version' => '8.3'])
        ->assertStatus(202);
});

it('still treats a complete version as done', function () {
    // A healthy version is a no-op, not an apt run on every press.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    fakePhp();

    $this->actingAs($admin)
        ->postJson('/api/php/versions', ['version' => '8.4'])
        ->assertStatus(200);
});

it('does not go on calling a version failed once it is installed and complete', function () {
    // A failure recorded mid-install (apt's php-fpm start) must not stick once the version is
    // complete.
    fakePhp();
    $tracker = app(InstallTracker::class);
    $tracker->start('php', '8.3');
    $tracker->fail('php', '8.3', null, 'unknown', 'ref-1');

    $row = collect(phpSettings()['versions'])->firstWhere('version', '8.3');

    expect($row['missing_packages'])->toBe([])
        ->and($row['status'])->toBe('ready')
        ->and($row['reason'])->toBeNull();
});

it('still reports the failure while the version is incomplete', function () {
    fakePhp(bare: true);
    $tracker = app(InstallTracker::class);
    $tracker->start('php', '8.3');
    $tracker->fail('php', '8.3', null, 'unknown', 'ref-1');

    expect(collect(phpSettings()['versions'])->firstWhere('version', '8.3')['status'])->toBe('failed');
});

it('keeps the server default when installing a newer PHP', function () {
    // Debian's `php` group is in auto mode and the newest version has the highest priority, so
    // apt moves the default to it on install, taking cron, shells and new sites along.
    $runs = new ArrayObject;
    $state = new ArrayObject(['default' => '8.4']);
    $phpDir = $this->phpDir;

    Process::fake(function ($process) use ($runs, $state, $phpDir) {
        $command = $process->command;
        $runs[] = $command;

        if (($command[0] ?? '') === 'update-alternatives' && in_array('--query', $command, true)) {
            return Process::result(output: "Name: php\nStatus: auto\nValue: /usr/bin/php{$state['default']}\n");
        }

        if (($command[0] ?? '') === 'update-alternatives' && ($command[1] ?? '') === '--set' && ($command[2] ?? '') === 'php') {
            $state['default'] = substr($command[3], strlen('/usr/bin/php'));
        }

        if (($command[0] ?? '') === 'apt-get' && ($command[1] ?? '') === 'install') {
            File::makeDirectory("{$phpDir}/8.5/fpm", 0755, true, true);
            $state['default'] = '8.5';
        }

        if (($command[0] ?? '') === 'apt-cache' && ($command[1] ?? '') === 'policy') {
            return Process::result(output: collect(array_slice($command, 2))
                ->map(fn ($package) => "{$package}:\n  Installed: (none)\n  Candidate: 1.0\n")->implode(''));
        }

        return Process::result(exitCode: 0);
    });

    app(PhpRuntime::class)->install('8.5');

    expect($state['default'])->toBe('8.4')
        ->and(collect($runs)->contains(fn ($c) => $c === ['update-alternatives', '--set', 'php', '/usr/bin/php8.4']))->toBeTrue();
});

it('leaves the default alone when an install does not move it', function () {
    $runs = fakePhp(default: '8.4');

    app(PhpRuntime::class)->install('8.2');

    expect(collect($runs)->contains(fn ($r) => ($r['command'][0] ?? '') === 'update-alternatives' && ($r['command'][1] ?? '') === '--set'))->toBeFalse();
});

it('lets a failed install be dismissed, not only retried (OLD-7)', function () {
    fakePhp(absent: ['8.2']);
    $tracker = app(InstallTracker::class);
    $tracker->start('php', '8.2');
    $tracker->fail('php', '8.2', null, 'network');

    phpCall('DELETE', '/api/php/versions/8.2')->assertNoContent();

    expect(RuntimeInstall::query()->where('version', '8.2')->exists())->toBeFalse();
});

it('still answers 404 for a version that is neither installed nor failed', function () {
    fakePhp(absent: ['8.2']);

    phpCall('DELETE', '/api/php/versions/8.2')->assertNotFound();
});

/*
| OpenLiteSpeed: dpkg skips the folders holding the panel's ionCube files, so deleting those
| leaves `lsphpNN/` as a skeleton of empty folders. Only empty folders are swept, only on that
| stack, only when the panel's ionCube was there.
*/
it('sweeps the empty folders of an OpenLiteSpeed version once its ionCube files are gone', function () {
    config(['server.php_stacks.lsphp.dir' => '/usr/local/lsws']);
    app()->instance(PhpStack::class, app(LsphpPhpStack::class));
    $ionCube = Mockery::mock(IonCubeLoader::class);
    $ionCube->shouldReceive('panelFiles')->with('8.5')->andReturn(['/usr/local/lsws/lsphp85/etc/php/8.5/mods-available/01-ioncube.ini']);
    app()->instance(IonCubeLoader::class, $ionCube);
    $runs = fakePhp(default: '8.4');

    app(PhpRuntime::class)->uninstall('8.5');

    $commands = collect($runs)->pluck('command')->values();
    $sweep = $commands->search(['find', '/usr/local/lsws/lsphp85', '-depth', '-type', 'd', '-empty', '-delete']);
    expect($sweep)->not->toBeFalse()
        ->and($sweep)->toBeGreaterThan($commands->search(['rm', '-f', '/usr/local/lsws/lsphp85/etc/php/8.5/mods-available/01-ioncube.ini']));
});

it('sweeps nothing when the panel had no ionCube there, or on PHP-FPM', function (bool $lsphp, array $files) {
    if ($lsphp) {
        app()->instance(PhpStack::class, app(LsphpPhpStack::class));
    }
    $ionCube = Mockery::mock(IonCubeLoader::class);
    $ionCube->shouldReceive('panelFiles')->andReturn($files);
    app()->instance(IonCubeLoader::class, $ionCube);
    $runs = fakePhp(default: '8.4');

    app(PhpRuntime::class)->uninstall('8.3');

    expect(collect($runs)->pluck('command')->contains(fn ($c) => ($c[0] ?? null) === 'find' && in_array('-empty', $c, true)))->toBeFalse();
})->with([
    'OLS without ionCube' => [true, []],
    'PHP-FPM with ionCube' => [false, ['/ext/ioncube_loader_lin_8.3.so']],
]);
