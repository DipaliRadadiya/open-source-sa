<?php

use App\Enums\InstallStatus;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Jobs\InstallPhpVersion;
use App\Jobs\RemovePhpVersion;
use App\Models\Application;
use App\Models\RuntimeInstall;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Php\IonCubeLoader;
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

function fakePhp(string $default = '8.4', bool $ok = true, array $absent = [], bool $bare = false): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $default, $ok, $absent, $bare) {
        $runs[] = ['command' => $process->command, 'env' => $process->environment ?? []];
        $command = $process->command;

        // `$ok: false` fails the mutating commands only — the reads still have
        // to answer, or a test cannot get as far as the operation it is about.
        if (! $ok && ($command[0] ?? '') === 'apt-get') {
            return Process::result(exitCode: 1, errorOutput: 'E: Could not get lock');
        }

        return match (true) {
            ($command[0] ?? '') === 'update-alternatives' && in_array('--query', $command, true) => Process::result(
                output: "Name: php\nLink: /usr/bin/php\nStatus: auto\nBest: /usr/bin/php{$default}\nValue: /usr/bin/php{$default}\n"
            ),
            // `apt-cache policy <pkg>` — the availability check install()
            // makes before handing a name to apt. Answered per package from
            // $absent, so a test can model a name the index does not have.
            //
            // The shape matters: an unknown package prints NOTHING and still
            // exits 0, and a known-but-uninstallable one prints
            // `Candidate: (none)`. A fake that answered with an exit code
            // would prove the opposite of what happens on a server.
            ($command[0] ?? '') === 'apt-cache' && ($command[1] ?? '') === 'policy' => Process::result(
                output: in_array($command[2] ?? '', $absent, true)
                    ? ''
                    : "{$command[2]}:\n  Installed: (none)\n  Candidate: 1.0\n"
            ),
            ($command[0] ?? '') === 'apt-cache' => Process::result(
                output: "php8.2-fpm - server-side scripting\nphp8.3-fpm - server-side scripting\nphp8.4-fpm - server-side scripting\n"
            ),
            // `dpkg-query -W` — which of the base packages are actually on the
            // box. A version the panel installed has all of them, which is the
            // default here; `$bare` models the case that prompted this, a
            // version that arrived as somebody else's apt dependency with only
            // the interpreter.
            //
            // dpkg-query exits non-zero when a name is unknown, and that is
            // the ordinary answer rather than a failure — modelled, because a
            // fake that always exits 0 would hide the expected-exit handling.
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

    // Managing that symlink by hand would fight the package manager on its
    // own ground; update-alternatives is the supported mechanism.
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

    // This is the one that would take the panel offline from inside the
    // panel, with no way back in to undo it.
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
        ->assertJsonFragment(['message' => 'PHP 8.3 is used by Legacy shop. Change those sites first.']);
});

it('queues the removal instead of purging inside the request', function () {
    Queue::fake();
    fakePhp(default: '8.4');

    // 202, not 204. apt takes minutes and nginx ends a request at
    // fastcgi_read_timeout, so purging here handed the browser a timeout
    // while the work carried on — the screen never refreshed, the version
    // disappeared on its own, and pressing Remove again answered 404.
    phpCall('DELETE', '/api/php/versions/8.3')->assertStatus(202);

    Queue::assertPushed(RemovePhpVersion::class, fn ($job) => $job->version === '8.3');
});

it('marks the version as removing before the worker picks it up', function () {
    Queue::fake();
    fakePhp(default: '8.4');

    phpCall('DELETE', '/api/php/versions/8.3')->assertStatus(202);

    // Recorded before dispatch: a client reloading straight after the 202
    // must see the version marked rather than watch it sit there looking
    // untouched.
    expect(RuntimeInstall::where('version', '8.3')->first()?->status)
        ->toBe(InstallStatus::Removing);
});

it('purges and then clears what the purge cannot', function () {
    // The panel writes a pool file per site into <version>/fpm/pool.d. dpkg
    // does not own those, so a directory still holding them survives the
    // purge — and detection reads exactly these directories, which is why a
    // removed version stayed on the screen through a reload.
    $runs = fakePhp(default: '8.4');

    app(PhpRuntime::class)->uninstall('8.3');

    expect(collect($runs)->pluck('command'))
        ->toContain(['apt-get', 'purge', '-y', 'php8.3-*'])
        ->toContain(['rm', '-rf', config('server.php_dir').'/8.3']);
});

/*
 * The panel's ionCube ini and loader are not dpkg's, so they outlived the
 * purge. On OpenLiteSpeed the ini sits in the version's scan dir, and
 * reinstalling that version loaded ionCube again with nothing saying so.
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
    // Stripping a version's configuration after apt refused to remove the
    // version would leave a working PHP with no config at all — worse than
    // the leftovers this exists to sweep up.
    // The failure this fake injects is "E: Could not get lock", which is one of
    // `server.transient.patterns` — so `ServerOps::apt()` retried it on the
    // real budget, 40 attempts 15 seconds apart. That is a genuine `usleep` of
    // **600 seconds** inside one test, and the whole suite runs in 764: four
    // fifths of every run was this line waiting. The retry budget itself is
    // covered by ServerOpsRetryTest, which sets its own; here it is incidental
    // to a test about not deleting a directory.
    config(['server.apt.lock_attempts' => 2, 'server.apt.lock_delay_ms' => 0]);

    $runs = fakePhp(default: '8.4', ok: false);

    expect(fn () => app(PhpRuntime::class)->uninstall('8.3'))
        ->toThrow(SettingOperationException::class);

    expect(collect($runs)->pluck('command'))
        ->not->toContain(['rm', '-rf', config('server.php_dir').'/8.3']);
});

it('installs a usable PHP, not a bare interpreter', function () {
    // A bare php8.2-fpm has no mysql, no curl, no mbstring — every
    // application in the marketplace would fail on it.
    //
    // Through fakePhp(), which answers `apt-cache policy`. An earlier version
    // re-faked Process with a stub that returned nothing for every command,
    // and once install() started checking availability that stub meant "the
    // index has none of these" — so the assertion failed for a reason that
    // had nothing to do with the base set. A fake that answers less than the
    // real thing tests the fake.
    $runs = fakePhp();

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');
    expect($install)->toContain('php8.2-fpm', 'php8.2-mysql', 'php8.2-curl', 'php8.2-mbstring');
});

it('drops a package this server cannot install, and keeps the rest', function () {
    // The reported failure: `E: Unable to locate package lsphp85-opcache`.
    // apt fails the WHOLE transaction on one unknown name, so a single gap in
    // LiteSpeed's per-version package set meant "install PHP 8.5" installed
    // nothing at all — mysql and curl went down with the missing one.
    $runs = fakePhp(absent: ['php8.2-mbstring']);

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');

    expect($install)->not->toContain('php8.2-mbstring')
        // ...and the ones that DO exist still get installed, which is the
        // whole point: one absent name must not cost the others.
        ->and($install)->toContain('php8.2-fpm', 'php8.2-mysql', 'php8.2-curl');
});

it('never drops the interpreter itself', function () {
    // Filtering the thing being installed would turn "this version is not
    // available on this server" into a successful install of nothing.
    // Extensions are degradable; the interpreter is not.
    $runs = fakePhp(absent: ['php8.2-fpm', 'php8.2-cli', 'php8.2-common', 'php8.2-mysql']);

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->pluck('command')->first(fn ($c) => ($c[0] ?? '') === 'apt-get' && ($c[1] ?? '') === 'install');

    // Still handed to apt, so apt refuses out loud rather than the panel
    // quietly installing an empty set and calling it done.
    expect($install)->toContain('php8.2-fpm');
});

it('installs the full set when the availability check itself fails', function () {
    // A filter that cannot see must not filter. If apt-cache cannot run --
    // lock held, index broken, binary missing -- reading "cannot confirm" as
    // "not there" would strip every extension and report success, which is
    // the same silent half-install this filtering exists to prevent.
    //
    // Degrading to the previous behaviour puts apt back in charge: if a
    // package really is missing it says so, loudly, as it always did.
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

    // apt takes a lock; a second run for the same version would sit waiting
    // for the first and then repeat its work.
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
    // `php8.x-cli` registers three groups and the panel moved one of them, so
    // `php -v` and `phar -v` could report different versions — the kind of
    // thing a build script finds weeks later.
    $runs = fakePhp(default: '8.4');

    phpCall('PUT', '/api/php/default', ['default' => '8.3'])->assertOk();

    expect(collect($runs)->pluck('command'))
        ->toContain(['update-alternatives', '--set', 'phar', '/usr/bin/phar8.3'])
        ->toContain(['update-alternatives', '--set', 'phar.phar', '/usr/bin/phar.phar8.3']);
});

it('still changes the default when the box has no phar alternative', function () {
    // A box assembled some other way can have the interpreter without the phar
    // links. Refusing the whole change over that would report a change that
    // did happen as one that did not.
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
    // 🔴 Reported from a real OpenLiteSpeed server: PHP 8.3 installed cleanly,
    // then Nextcloud refused to install because curl was missing — a package
    // that exists upstream, is named correctly by the panel, and was never
    // asked for.
    //
    // `installablePackages()` asks `apt-cache policy` whether each extension
    // exists, and that reads the LOCAL index. On a box whose index predates
    // the repository, every extension answers "no", every one is skipped by
    // the degrade-gracefully filter, and a bare interpreter installs while
    // reporting success.
    //
    // Ordering is the assertion, not presence: a refresh after the checks
    // answers a question that has already been asked wrongly.
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
    // `apt-get update` exits non-zero when ANY configured source fails,
    // including one with nothing to do with PHP. Refusing to install over an
    // unrelated 404 would turn a working server into one that cannot add a PHP
    // version — a worse failure than the stale index this guards against.
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
| 🔴 These two drifted, and the gap reached a real server: the panel's list
| omitted `sqlite3`, so PHP 8.5 added from the PHP screen had no pdo_sqlite.
| Made the system default, it left bare `php` — which the deploy runbook uses —
| unable to open the panel's own SQLite database:
|
|   In Connector.php line 67:  could not find driver
|
| OpenLiteSpeed never had this bug, and the reason is instructive: the same
| drift happened there first, caused an "install PHP 8.3" that installed
| nothing, and `OlsInstallerTest` has asserted parity ever since. The FPM stack
| had no such guard. This is it.
|
| install.sh is the source of truth in both, for the reason the OLS test gives:
| it is the set proven on hardware. Whichever list a future edit changes, the
| other has to move with it.
*/

it('installs from the panel exactly what install.sh installs', function () {
    $path = base_path('../install.sh');

    if (! is_file($path)) {
        test()->markTestSkipped('install.sh is not in this checkout');
    }

    // The FPM branch only. `install_ols_packages` builds `${lsphp}-` names and
    // is covered by OlsInstallerTest; matching both here would compare two
    // stacks' lists to one config key.
    preg_match_all('/php\$\{PHP_VERSION\}-([a-z0-9]+)/', (string) file_get_contents($path), $matches);

    $fromInstaller = array_values(array_unique($matches[1]));
    $fromConfig = (array) config('server.runtimes.php.base_packages');

    expect($fromInstaller)->not->toBeEmpty('install.sh installs no php packages?');

    sort($fromInstaller);
    sort($fromConfig);

    expect($fromConfig)->toBe($fromInstaller);
});

it('always installs the driver for the panel\'s own database', function () {
    // Named on its own rather than left to the parity check. Losing sqlite3 is
    // not one missing extension among sixteen: the panel stores itself in
    // SQLite, so a version without it breaks `artisan` — migrations, the
    // scheduler, the queue — the moment it becomes the default. A parity test
    // says "the lists match"; this says which package must never leave.
    expect((array) config('server.runtimes.php.base_packages'))->toContain('sqlite3');
});

/*
| A version the panel did not install.
|
| 🔴 Found on a real OpenLiteSpeed server, 2026-09-21. The `openlitespeed`
| package pulls in `lsphp83` as its OWN dependency, so the panel listed a
| version nobody asked for — and it was a bare interpreter:
|
|   PHP 8.3 (apt dependency)   curl ✗  sqlite3 ✗  redis ✗  intl ✗  pgsql ✗
|   PHP 8.4 (panel installed)  curl ✓  sqlite3 ✓  redis ✓  intl ✓  pgsql ✓
|
| In the version picker the two look identical. A site put on 8.3 then fails
| with "curl is not installed" — the report this whole sequence began from,
| reproduced on a brand-new server with every earlier fix in place.
|
| And the one control that could have repaired it refused to act: `store()`
| asked `installed()`, which only means the interpreter exists.
|
| The same shape exists on FPM — a hand-installed php8.1-fpm has an
| interpreter and none of the set — so none of this is OpenLiteSpeed-specific.
*/

it('reports the base packages a half-installed version is missing', function () {
    $runs = fakePhp(bare: true);

    $missing = app(PhpRuntime::class)->missingBasePackages('8.3');

    expect($missing)->not->toBeEmpty()
        ->and($missing)->toContain('php8.3-curl')
        ->and($missing)->toContain('php8.3-sqlite3')
        // Never the interpreter: its presence is what "installed" means, and
        // its absence would be a different state entirely.
        ->and($missing)->not->toContain('php8.3-fpm')
        ->and(collect($runs)->pluck('command')->contains(fn ($c) => ($c[0] ?? '') === 'dpkg-query'))->toBeTrue();
});

it('reports nothing missing for a version the panel installed', function () {
    fakePhp();

    expect(app(PhpRuntime::class)->missingBasePackages('8.4'))->toBe([]);
});

it('never reports a package the index does not have', function () {
    // LiteSpeed compiles mbstring, xml, zip, gd, bcmath and soap into the
    // interpreter, so those names exist nowhere. Calling them "missing" would
    // be alarming and false — the version has the capability.
    fakePhp(bare: true, absent: ['php8.3-mbstring', 'php8.3-soap']);

    $missing = app(PhpRuntime::class)->missingBasePackages('8.3');

    expect($missing)->not->toContain('php8.3-mbstring')
        ->and($missing)->not->toContain('php8.3-soap')
        ->and($missing)->toContain('php8.3-curl');
});

it('completes a half-installed version instead of calling it done', function () {
    // The bug: "Install PHP 8.3" answered "already installed" and did nothing,
    // on a version the panel was still offering for new sites. apt is
    // idempotent, so falling through to the install is the existing path.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    fakePhp(bare: true);

    $this->actingAs($admin)
        ->postJson('/api/php/versions', ['version' => '8.3'])
        ->assertStatus(202);
});

it('still treats a complete version as done', function () {
    // The pre-existing behaviour, which must not regress: a healthy version
    // is a no-op, not an apt run on every press.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    fakePhp();

    $this->actingAs($admin)
        ->postJson('/api/php/versions', ['version' => '8.4'])
        ->assertStatus(200);
});
