<?php

use App\Enums\InstallStatus;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Jobs\InstallPhpVersion;
use App\Jobs\RemovePhpVersion;
use App\Models\Application;
use App\Models\RuntimeInstall;
use App\Models\SystemUser;
use App\Models\User;
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

function fakePhp(string $default = '8.4', bool $ok = true): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $default, $ok) {
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
            ($command[0] ?? '') === 'apt-cache' => Process::result(
                output: "php8.2-fpm - server-side scripting\nphp8.3-fpm - server-side scripting\nphp8.4-fpm - server-side scripting\n"
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

it('does not clear the directory when the purge failed', function () {
    // Stripping a version's configuration after apt refused to remove the
    // version would leave a working PHP with no config at all — worse than
    // the leftovers this exists to sweep up.
    $runs = fakePhp(default: '8.4', ok: false);

    expect(fn () => app(PhpRuntime::class)->uninstall('8.3'))
        ->toThrow(SettingOperationException::class);

    expect(collect($runs)->pluck('command'))
        ->not->toContain(['rm', '-rf', config('server.php_dir').'/8.3']);
});

it('installs a usable PHP, not a bare interpreter', function () {
    fakePhp();
    app(PhpRuntime::class)->install('8.2');

    // A bare php8.2-fpm has no mysql, no curl, no mbstring — every
    // application in the marketplace would fail on it.
    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });
    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->first(fn ($c) => ($c[0] ?? '') === 'apt-get');
    expect($install)->toContain('php8.2-fpm', 'php8.2-mysql', 'php8.2-curl', 'php8.2-mbstring');
});

it('runs apt unattended, or it waits for a prompt nobody will answer', function () {
    $runs = fakePhp();

    app(PhpRuntime::class)->install('8.2');

    $install = collect($runs)->first(fn ($run) => ($run['command'][0] ?? '') === 'apt-get');
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
