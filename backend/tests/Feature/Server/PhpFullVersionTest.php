<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Models\ServerCapability;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * `full_version` on GET /php: the exact version the interpreter reports, where
 * `version` only ever said `8.4`.
 *
 * The binaries are real executable files in a temp tree — the panel checks one
 * exists before it spawns anything — and Process is faked, so each one answers
 * as the test scripts it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->root = sys_get_temp_dir().'/sv-oss-fullver-'.getmypid();
    File::deleteDirectory($this->root);

    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->root}/etc/{$version}/fpm", 0755, true);
        foreach (["bin/php{$version}", "sbin/php-fpm{$version}"] as $binary) {
            File::ensureDirectoryExists(dirname("{$this->root}/{$binary}"));
            File::put("{$this->root}/{$binary}", "#!/bin/sh\n");
            chmod("{$this->root}/{$binary}", 0755);
        }
    }

    // 8.4 is what bare `php` runs.
    symlink("{$this->root}/bin/php8.4", "{$this->root}/php-link");

    config([
        'server.php_dir' => "{$this->root}/etc",
        'server.php_binary_pattern' => "{$this->root}/bin/php{version}",
        'server.php_fpm_binary_pattern' => "{$this->root}/sbin/php-fpm{version}",
        'server.php_path_link' => "{$this->root}/php-link",
    ]);

    Cache::flush();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fullVersionHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/**
 * Every PHP answers `$answers[<binary path>]`; anything else (apt-cache,
 * update-alternatives, dpkg-query) gets an empty success. A callable answer is
 * run instead, for a binary that has to misbehave.
 *
 * @param  array<string, string|callable>  $answers
 */
function fakePhpBinaries(array $answers, ?ArrayObject $asked = null): void
{
    Process::fake(function ($process) use ($answers, $asked) {
        $command = $process->command;

        if (! is_array($command) || ! isset($answers[$command[0] ?? ''])) {
            return Process::result(output: '');
        }

        $asked?->append($command);

        $answer = $answers[$command[0]];

        return is_callable($answer) ? $answer() : Process::result(output: $answer);
    });
}

function phpFullVersionRows(): array
{
    return collect(test()->withHeaders(fullVersionHeaders())->getJson('/api/php')->assertOk()->json('php.versions'))
        ->keyBy('version')
        ->all();
}

it('reports the exact version for every ready row', function () {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ]);

    $rows = phpFullVersionRows();

    expect($rows['8.4']['full_version'])->toBe('8.4.14')
        ->and($rows['8.3']['full_version'])->toBe('8.3.27')
        // `version` is the identifier everything else keys on: untouched.
        ->and($rows['8.4']['version'])->toBe('8.4')
        ->and($rows['8.4']['path'])->toBe(test()->root.'/bin/php8.4');
});

it('asks the row\'s own binary, without php.ini, and without sudo', function () {
    $asked = new ArrayObject;
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ], $asked);

    phpFullVersionRows();

    expect(collect($asked)->all())->toContain(
        [test()->root.'/bin/php8.4', '-n', '-r', 'echo PHP_VERSION;'],
        [test()->root.'/bin/php8.3', '-n', '-r', 'echo PHP_VERSION;'],
    )->and(collect($asked)->every(fn (array $c) => $c[0] !== 'sudo'))->toBeTrue();

    Process::assertRan(fn ($p) => $p->timeout === 3 && ($p->command[1] ?? null) === '-n');
});

it('names the default row\'s exact version at the top level', function () {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ]);

    $this->withHeaders(fullVersionHeaders())->getJson('/api/php')
        ->assertOk()
        ->assertJsonPath('php.default', '8.4')
        ->assertJsonPath('php.default_full_version', '8.4.14');

    // Move bare `php` to 8.3: the top-level field follows the default row.
    unlink(test()->root.'/php-link');
    symlink(test()->root.'/bin/php8.3', test()->root.'/php-link');

    $this->withHeaders(fullVersionHeaders())->getJson('/api/php')
        ->assertJsonPath('php.default', '8.3')
        ->assertJsonPath('php.default_full_version', '8.3.27');
});

it('has no default full version when there is no default', function () {
    unlink(test()->root.'/php-link');
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ]);

    $this->withHeaders(fullVersionHeaders())->getJson('/api/php')
        ->assertOk()
        ->assertJsonPath('php.default', null)
        ->assertJsonPath('php.default_full_version', null);
});

it('keeps the key on a version that is installing, and does not run its binary', function () {
    $asked = new ArrayObject;
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ], $asked);

    // 8.3 is on disk but apt is reinstalling it; 8.2 has nothing on disk yet.
    foreach (['8.3', '8.2'] as $version) {
        RuntimeInstall::query()->create([
            'runtime' => 'php', 'version' => $version, 'status' => InstallStatus::Installing, 'started_at' => now(),
        ]);
    }

    $rows = phpFullVersionRows();

    expect($rows['8.3']['status'])->toBe('installing')
        ->and($rows['8.3'])->toHaveKey('full_version')->and($rows['8.3']['full_version'])->toBeNull()
        ->and($rows['8.2']['status'])->toBe('installing')
        ->and($rows['8.2'])->toHaveKey('full_version')->and($rows['8.2']['full_version'])->toBeNull()
        ->and($rows['8.4']['full_version'])->toBe('8.4.14');

    expect(collect($asked)->pluck(0)->all())->not->toContain(test()->root.'/bin/php8.3');
});

it('is null for a version being removed or one whose install failed', function () {
    $asked = new ArrayObject;
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ], $asked);

    RuntimeInstall::query()->create([
        'runtime' => 'php', 'version' => '8.3', 'status' => InstallStatus::Removing, 'started_at' => now(),
    ]);
    RuntimeInstall::query()->create([
        'runtime' => 'php', 'version' => '8.1', 'status' => InstallStatus::Failed, 'reason' => 'package_not_found', 'started_at' => now(),
    ]);

    $rows = phpFullVersionRows();

    expect($rows['8.3']['status'])->toBe('removing')
        ->and($rows['8.3']['full_version'])->toBeNull()
        ->and($rows['8.1']['status'])->toBe('failed')
        ->and($rows['8.1']['full_version'])->toBeNull();

    expect(collect($asked)->pluck(0)->all())->not->toContain(test()->root.'/bin/php8.3');
});

it('is null for a failed removal that left the binary on disk', function () {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ]);

    // A removal apt refused: the version is still on disk and stays `failed`
    // (see RuntimeProgress::stillCurrent), so it is not trusted.
    RuntimeInstall::query()->create([
        'runtime' => 'php', 'version' => '8.3', 'status' => InstallStatus::Failed, 'reason' => 'remove_failed', 'started_at' => now(),
    ]);

    $rows = phpFullVersionRows();

    expect($rows['8.3']['status'])->toBe('failed')
        ->and($rows['8.3']['full_version'])->toBeNull();
});

it('is null, quickly and without failing the response, when a binary hangs', function () {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => function () {
            throw new ProcessTimedOutException(
                new SymfonyTimedOut(new SymfonyProcess(['php']), SymfonyTimedOut::TYPE_GENERAL),
                new FakeProcessResult(exitCode: 124),
            );
        },
    ]);

    $started = microtime(true);
    $rows = phpFullVersionRows();

    expect($rows['8.3']['full_version'])->toBeNull()
        ->and($rows['8.3']['status'])->toBe('ready')
        ->and($rows['8.4']['full_version'])->toBe('8.4.14')
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('is null when the binary exits non-zero', function () {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => fn () => Process::result(output: '8.3.27', errorOutput: 'Segmentation fault', exitCode: 139),
    ]);

    expect(phpFullVersionRows()['8.3']['full_version'])->toBeNull();
});

it('is null when the binary prints something that is not a version', function (string $printed) {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => $printed,
    ]);

    expect(phpFullVersionRows()['8.3']['full_version'])->toBeNull();
})->with([
    'warning text' => ["PHP Warning:  Module \"redis\" already loaded in Unknown on line 0\n8.3.27"],
    'empty' => [''],
    'two parts only' => ['8.3'],
    'html' => ['<html>502</html>'],
    'a line of garbage' => ['not a version'],
]);

it('is null when the binary reports a different minor version than its row', function () {
    // A pattern that resolves to one `php` on PATH, or a stale symlink: 8.4
    // under the 8.3 row would be wrong, so nothing is shown.
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.4.14',
    ]);

    $rows = phpFullVersionRows();

    expect($rows['8.3']['full_version'])->toBeNull()
        ->and($rows['8.4']['full_version'])->toBe('8.4.14');
});

it('keeps a real pre-release marker and drops a distribution suffix', function (string $printed, string $expected) {
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => $printed,
        test()->root.'/bin/php8.3' => '8.3.27',
    ]);

    expect(phpFullVersionRows()['8.4']['full_version'])->toBe($expected);
})->with([
    'plain' => ['8.4.14', '8.4.14'],
    'release candidate' => ['8.4.0RC1', '8.4.0RC1'],
    'beta' => ['8.4.0beta2', '8.4.0beta2'],
    'alpha' => ['8.4.0alpha1', '8.4.0alpha1'],
    'ubuntu suffix' => ['8.4.2-1ubuntu2.14', '8.4.2'],
    'debian plus suffix' => ['8.4.14+deb12u1', '8.4.14'],
    'trailing newline' => ["8.4.14\n", '8.4.14'],
]);

it('is null for a version whose cli binary is not installed, without spawning anything', function () {
    unlink(test()->root.'/bin/php8.3');
    $asked = new ArrayObject;
    fakePhpBinaries([test()->root.'/bin/php8.4' => '8.4.14'], $asked);

    $rows = phpFullVersionRows();

    expect($rows['8.3']['status'])->toBe('ready')
        ->and($rows['8.3']['full_version'])->toBeNull()
        ->and($rows['8.4']['full_version'])->toBe('8.4.14');
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === test()->root.'/bin/php8.3');
});

it('does not spawn the binaries again while they are unchanged, and notices an upgrade', function () {
    $asked = new ArrayObject;
    fakePhpBinaries([
        test()->root.'/bin/php8.4' => '8.4.14',
        test()->root.'/bin/php8.3' => '8.3.27',
    ], $asked);

    phpFullVersionRows();
    phpFullVersionRows();

    expect(collect($asked)->count())->toBe(2);

    // apt replaces the file on upgrade: new size and mtime, so a new key.
    $binary = test()->root.'/bin/php8.4';
    File::put($binary, "#!/bin/sh\n# 8.4.15\n");
    touch($binary, time() + 60);

    fakePhpBinaries([
        $binary => '8.4.15',
        test()->root.'/bin/php8.3' => '8.3.27',
    ], $asked);

    expect(phpFullVersionRows()['8.4']['full_version'])->toBe('8.4.15');
});

it('remembers a failed read only briefly', function () {
    $answer = 'broken';
    $binary = test()->root.'/bin/php8.3';

    Process::fake(function ($process) use (&$answer, $binary) {
        return ($process->command[0] ?? '') === $binary
            ? Process::result(output: $answer)
            : Process::result(output: '');
    });

    expect(phpFullVersionRows()['8.3']['full_version'])->toBeNull();

    // Fixed on disk, but the miss is still remembered: a hung binary must not
    // cost its timeout on every poll.
    $answer = '8.3.27';
    expect(phpFullVersionRows()['8.3']['full_version'])->toBeNull();

    $this->travel(61)->seconds();

    expect(phpFullVersionRows()['8.3']['full_version'])->toBe('8.3.27');
});

it('keeps the permission: no php view, no response', function () {
    fakePhpBinaries([test()->root.'/bin/php8.4' => '8.4.14']);

    $limited = User::factory()->create();
    $token = $limited->createToken('t')->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/php')
        ->assertForbidden();

    Process::assertNothingRan();
});

it('reads the CLI beside the LSAPI binary on OpenLiteSpeed', function () {
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'ols',
        'web_server' => 'openlitespeed',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $lsws = test()->root.'/lsws';
    File::makeDirectory("{$lsws}/lsphp85/bin", 0755, true);
    File::makeDirectory("{$lsws}/lsphp84/bin", 0755, true);
    foreach (['lsphp85', 'lsphp84'] as $tree) {
        foreach (['php', 'lsphp'] as $name) {
            File::put("{$lsws}/{$tree}/bin/{$name}", "#!/bin/sh\n");
            chmod("{$lsws}/{$tree}/bin/{$name}", 0755);
        }
    }

    config(['server.php_stacks.lsphp.dir' => $lsws]);
    unlink(test()->root.'/php-link');
    symlink("{$lsws}/lsphp84/bin/php", test()->root.'/php-link');

    $asked = new ArrayObject;
    fakePhpBinaries([
        "{$lsws}/lsphp85/bin/php" => '8.5.0RC1',
        "{$lsws}/lsphp84/bin/php" => '8.4.14',
    ], $asked);

    $response = $this->withHeaders(fullVersionHeaders())->getJson('/api/php')->assertOk();
    $rows = collect($response->json('php.versions'))->keyBy('version');

    expect($rows['8.5']['path'])->toBe("{$lsws}/lsphp85/bin/php")
        ->and($rows['8.5']['full_version'])->toBe('8.5.0RC1')
        ->and($rows['8.4']['full_version'])->toBe('8.4.14');

    $response->assertJsonPath('php.default', '8.4')->assertJsonPath('php.default_full_version', '8.4.14');

    // Only the CLI answered — never the LSAPI build.
    expect(collect($asked)->pluck(0)->all())->each->not->toEndWith('/lsphp');
});
