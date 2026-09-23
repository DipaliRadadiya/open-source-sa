<?php

use App\Enums\InstallStatus;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Jobs\InstallIonCubeLoader;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\IonCubeLoader;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\ServerOps;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * The ionCube Loader — a closed-source `zend_extension`, one file per PHP
 * version, downloaded from the vendor rather than installed from apt.
 *
 * 🔴 Everything asserted here about ionCube's own behaviour was measured on a
 * real loader (v15.5.0, PHP 8.4, x86-64) before it was written down:
 *
 *   - `01-ioncube.ini` ahead of `10-opcache.ini`  → loads, both report in -v
 *   - `20-ioncube.ini` behind it                   → "PHP Fatal error:
 *     [ionCube Loader] The Loader must appear as the first entry in the
 *     php.ini file", and PHP does not start at all
 *
 * That second one is why the ordering has a test of its own, and why nothing
 * reloads a web server before the stack's config test has passed: getting it
 * wrong does not degrade one site, it stops PHP for every site on the version.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

    $this->phpDir = sys_get_temp_dir().'/sv-oss-ioncube-'.getmypid();
    File::deleteDirectory($this->phpDir);

    foreach (['cli', 'fpm'] as $sapi) {
        File::makeDirectory("{$this->phpDir}/{$this->version}/{$sapi}/conf.d", 0755, true);
    }

    config([
        'server.php_dir' => $this->phpDir,
        'server.php_binary_pattern' => '/usr/bin/php{version}',
        'server.ioncube.versions' => [$this->version],
        'server.ioncube.urls.x86_64' => 'https://downloads.ioncube.example/loaders_x86-64.tar.gz',
        'server.ioncube.urls.aarch64' => 'https://downloads.ioncube.example/loaders_aarch64.tar.gz',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->phpDir));

/**
 * A 64-bit ELF shared object header for this machine, which is all
 * {@see IonCubeLoader::assertLoaderBinary()} reads before trusting the file.
 */
function elfHeader(): string
{
    $machine = php_uname('m') === 'aarch64' ? 0xB7 : 0x3E;

    return "\x7fELF\x02\x01\x01\x00".str_repeat("\x00", 8)
        ."\x03\x00".chr($machine)."\x00".str_repeat("\x00", 16);
}

/**
 * @param  array<string, mixed>  $options  zts, extract, configTest
 */
function fakeIonCube(array $options = []): ArrayObject
{
    $runs = new ArrayObject;
    $zts = $options['zts'] ?? false;
    $extract = $options['extract'] ?? true;
    $configTest = $options['config_test'] ?? true;
    $body = $options['body'] ?? elfHeader();

    Process::fake(function ($process) use ($runs, $zts, $extract, $configTest, $body, $options) {
        $command = (array) $process->command;
        $args = ($command[0] ?? '') === 'sudo' ? array_slice($command, 2) : $command;
        $runs[] = implode(' ', $args);

        if (isset($options['fail']) && ($options['fail'])($args)) {
            return Process::result(exitCode: 1, errorOutput: 'injected failure');
        }
        if ($options['real_files'] ?? false) {
            $ok = match ($args[0] ?? '') {
                'test' => ($args[1] ?? '') === '-e' ? file_exists($args[2]) : true,
                'cp' => copy($args[2], $args[3]),
                'install' => copy($args[3], $args[4]),
                'tee' => file_put_contents($args[1], $process->input) !== false,
                'rm' => ! file_exists($args[2]) || unlink($args[2]),
                default => null,
            };
            if ($ok !== null) {
                return Process::result(exitCode: $ok ? 0 : 1);
            }
        }
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-e') {
            return Process::result(exitCode: ($options['existing'] ?? false) ? 0 : 1);
        }
        // PHP answering questions about itself: the extension directory and
        // whether this build is thread-safe.
        if (str_contains((string) ($args[0] ?? ''), 'php') && in_array('-r', $args, true)) {
            $code = $args[array_search('-r', $args, true) + 1] ?? '';

            return str_contains($code, 'PHP_ZTS')
                ? Process::result(output: ($options['zts_output'] ?? ($zts ? '1' : '0'))."\n")
                : Process::result(output: $options['extension_dir'] ?? '/usr/lib/php/20240924');
        }

        if (($args[0] ?? '') === 'tar') {
            if (($args[1] ?? '') === '-tzf') {
                $suffix = $zts ? '_ts' : '';
                $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

                return Process::result(output: ($options['missing_member'] ?? false) ? '' : "ioncube/ioncube_loader_lin_{$version}{$suffix}.so\n");
            }
            if (! $extract) {
                return Process::result(exitCode: 2, errorOutput: 'tar: not found in archive');
            }

            // Write the member where the service expects to find it, which is
            // what a real extraction does.
            $dir = $args[array_search('-C', $args, true) + 1] ?? '';
            $member = $args[count($args) - 1];
            @mkdir($dir.'/'.dirname($member), 0750, true);
            file_put_contents($dir.'/'.$member, $body);

            return Process::result(exitCode: 0);
        }

        if (($args[0] ?? '') === 'php-fpm' || str_contains(implode(' ', $args), '-t')) {
            return $configTest
                ? Process::result(exitCode: 0)
                : Process::result(exitCode: 1, errorOutput: 'Failed loading Zend extension');
        }

        return Process::result(exitCode: 0);
    });

    Http::fake(['downloads.ioncube.example/*' => Http::response('archive-bytes')]);

    return $runs;
}

it('picks the loader that matches the PHP version and its thread safety', function () {
    // ionCube ships `_ts` and non-`_ts` builds and they are not
    // interchangeable. php-fpm and lsphp are both non-thread-safe, but that is
    // read off the interpreter rather than assumed.
    fakeIonCube();

    expect(app(IonCubeLoader::class)->loaderPath($this->version))
        ->toBe("/usr/lib/php/20240924/ioncube_loader_lin_{$this->version}.so");

    fakeIonCube(['zts' => true]);

    expect(app(IonCubeLoader::class)->loaderPath($this->version))
        ->toBe("/usr/lib/php/20240924/ioncube_loader_lin_{$this->version}_ts.so");
});

it('refuses a PHP version ionCube publishes no loader for, without downloading', function () {
    // PHP 8.0 is the live case: the panel offers it and the current archive
    // starts at 8.1. Refused before anything is fetched — a 29 MB download to
    // discover a file is absent is not a diagnosis.
    $runs = fakeIonCube();

    expect(fn () => app(IonCubeLoader::class)->install('8.0'))
        ->toThrow(PhpConfigException::class);

    Http::assertNothingSent();
    expect(collect($runs)->filter(fn (string $c) => str_starts_with($c, 'tar')))->toBeEmpty();
});

it('writes the loader ini ahead of opcache in every SAPI', function () {
    // 🔴 Measured: named `20-`, PHP fatals with "The Loader must appear as the
    // first entry in the php.ini file" and does not start. The number is the
    // feature.
    $runs = fakeIonCube();

    app(IonCubeLoader::class)->install($this->version);

    $written = collect($runs)->filter(fn (string $c) => str_starts_with($c, 'tee'));

    expect($written)->toHaveCount(2)
        ->and($written->every(fn (string $c) => str_contains($c, '/conf.d/01-ioncube.ini')))->toBeTrue();

    foreach (['cli', 'fpm'] as $sapi) {
        expect($written->contains(fn (string $c) => str_contains($c, "/{$sapi}/conf.d/01-ioncube.ini")))
            ->toBeTrue();
    }
});

it('installs the loader binary into PHP own extension directory', function () {
    $runs = fakeIonCube();

    app(IonCubeLoader::class)->install($this->version);

    expect(collect($runs)->contains(fn (string $c) => str_starts_with($c, 'install -m 0644')
        && str_ends_with($c, "/usr/lib/php/20240924/ioncube_loader_lin_{$this->version}.so")))
        ->toBeTrue();
});

it('refuses a download that is not an ELF object for this machine', function () {
    // The case a checksum would have caught, if the vendor published one: the
    // download succeeded and the contents are not a loader.
    $runs = fakeIonCube(['body' => '<html>404 Not Found</html>']);

    expect(fn () => app(IonCubeLoader::class)->install($this->version))
        ->toThrow(PhpConfigException::class);

    // Nothing reached the server's PHP directories.
    expect(collect($runs)->filter(fn (string $c) => str_starts_with($c, 'install -m')))->toBeEmpty()
        ->and(collect($runs)->filter(fn (string $c) => str_starts_with($c, 'tee')))->toBeEmpty();
});

it('takes the ini back out and reloads nothing when PHP refuses to start', function () {
    // The guard that decides whether a failed install is an error message or
    // an outage. The web server is still running a working configuration; a
    // reload here is the one action that could take every site down.
    $runs = fakeIonCube(['config_test' => false]);

    expect(fn () => app(IonCubeLoader::class)->install($this->version))
        ->toThrow(PhpConfigException::class);

    expect(collect($runs)->filter(fn (string $c) => str_contains($c, 'rm -f') && str_contains($c, '01-ioncube.ini')))
        ->toHaveCount(2)
        ->and(collect($runs)->filter(fn (string $c) => str_contains($c, 'systemctl reload')))
        ->toBeEmpty();
});

it('reports a version it cannot support without claiming anything about it', function () {
    fakeIonCube();

    expect(app(IonCubeLoader::class)->status('8.0'))
        ->toMatchArray(['supported' => false, 'installed' => false, 'loader_version' => null]);
});

describe('the endpoints', function () {
    it('queues the install and answers 202', function () {
        Queue::fake();
        fakeIonCube();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/php/versions/{$this->version}/ioncube")
            ->assertStatus(202);

        Queue::assertPushed(InstallIonCubeLoader::class);
    });

    it('refuses an unsupported version before queueing anything', function () {
        Queue::fake();
        fakeIonCube();

        // An *installed* PHP version that ionCube publishes no loader for —
        // which is the real shape of this case. A version that is not
        // installed at all is a 404 from the guard above, and that is correct:
        // there is no ionCube card for a PHP that is not there.
        config()->set('server.ioncube.versions', []);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/php/versions/{$this->version}/ioncube")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    });

    it('is a 404 for a PHP version that is not installed', function () {
        fakeIonCube();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/php/versions/8.0/ioncube')
            ->assertNotFound();
    });

    it('denies a user without the manage permission', function () {
        fakeIonCube();

        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/php/versions/{$this->version}/ioncube")
            ->assertForbidden();
    });
});

function ionCubeError(callable $operation): array
{
    try {
        $operation();
    } catch (PhpConfigException $e) {
        return $e->render(request())->getData(true);
    }

    test()->fail('Expected an ionCube failure');
}

it('distinguishes corrupt archives, missing members and failed extraction', function (array $options, string $key) {
    $runs = fakeIonCube($options);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__("errors/php.$key", ['version' => $this->version]))
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'install -m')))->toBeEmpty();
})->with([
    'corrupt archive' => [['fail' => fn ($args) => ($args[1] ?? '') === '-tzf'], 'ioncube_extraction_failed'],
    'absent loader' => [['missing_member' => true], 'ioncube_unsupported_version'],
    'extraction error' => [['extract' => false], 'ioncube_extraction_failed'],
]);

it('refuses unsafe or failed PHP discovery before downloading or writing', function (array $options) {
    $runs = fakeIonCube($options);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_discovery_failed'))
        ->and(collect($runs)->filter(fn ($c) => preg_match('/^(install|tee|cp|rm) /', $c)))->toBeEmpty();
    Http::assertNothingSent();
})->with([
    'empty directory' => [['extension_dir' => '']],
    'root directory' => [['extension_dir' => '/']],
    'relative directory' => [['extension_dir' => 'relative/path']],
    'traversal' => [['extension_dir' => '/usr/../tmp']],
    'invalid thread safety' => [['zts_output' => 'unknown']],
    'failed interpreter' => [['fail' => fn ($args) => in_array('-r', $args, true)]],
    'nonexistent directory' => [['fail' => fn ($args) => ($args[1] ?? '') === '-d' && ($args[0] ?? '') === 'test']],
]);

it('restores existing loader and INIs instead of deleting them on reinstall failure', function () {
    $runs = fakeIonCube(['existing' => true, 'config_test' => false]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_config_test_failed'));
    $restores = collect($runs)->filter(fn ($c) => preg_match('/^cp -p \S+\.bak /', $c))->values();
    expect($restores)->toHaveCount(3)
        ->and($restores[0])->toContain('.so.panel-ioncube-')
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'rm -f') && ! str_ends_with($c, '.bak')))->toBeEmpty()
        ->and(collect($runs)->filter(fn ($c) => str_contains($c, 'systemctl reload')))->toBeEmpty();
});

it('retains backups and reports failed recovery rather than successful rollback', function () {
    $runs = fakeIonCube([
        'existing' => true, 'config_test' => false,
        'fail' => fn ($args) => ($args[0] ?? '') === 'cp' && str_ends_with($args[2] ?? '', '.bak'),
    ]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_rollback_failed'))
        ->and($error)->toHaveKey('reference')
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'rm -f')))->toBeEmpty();
});

it('does not delete the binary or reload when removing an INI fails', function () {
    $runs = fakeIonCube([
        'existing' => true,
        'fail' => fn ($args) => ($args[0] ?? '') === 'rm' && str_ends_with($args[2] ?? '', '01-ioncube.ini'),
    ]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->remove($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_removal_failed'))
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'rm -f') && str_ends_with($c, '.so')))->toBeEmpty()
        ->and(collect($runs)->filter(fn ($c) => str_contains($c, 'systemctl reload')))->toBeEmpty();
});

it('keeps the loader if rollback cannot remove a newly written INI', function () {
    $runs = fakeIonCube([
        'config_test' => false,
        'fail' => fn ($args) => ($args[0] ?? '') === 'rm' && str_ends_with($args[2] ?? '', '01-ioncube.ini'),
    ]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_rollback_failed'))
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'rm -f') && str_ends_with($c, '.so')))->toBeEmpty();
});

it('records a failed reload as a failed install and retains recovery files', function () {
    $runs = fakeIonCube(['existing' => true, 'fail' => fn ($args) => ($args[0] ?? '') === 'systemctl']);
    $tracker = app(InstallTracker::class);
    $tracker->start(InstallIonCubeLoader::RUNTIME, $this->version);
    app()->call([new InstallIonCubeLoader($this->version, $this->admin->id), 'handle']);
    expect($tracker->current(InstallIonCubeLoader::RUNTIME, $this->version)->status)
        ->toBe(InstallStatus::Failed)
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'rm -f') && str_ends_with($c, '.bak')))->toBeEmpty();
    $this->assertDatabaseMissing('activity_logs', ['type' => 'php', 'action' => 'ioncube_installed']);
});

it('keeps the real cause of a failed install and says it in words', function () {
    // The job recorded every failure as `install_failed` and the card
    // returned only that code — a failed download and a failed config test
    // read the same, and the frontend showed the raw word.
    fakeIonCube(['existing' => true, 'fail' => fn ($args) => ($args[0] ?? '') === 'systemctl']);
    $tracker = app(InstallTracker::class);
    $tracker->start(InstallIonCubeLoader::RUNTIME, $this->version);
    app()->call([new InstallIonCubeLoader($this->version, $this->admin->id), 'handle']);

    expect($tracker->current(InstallIonCubeLoader::RUNTIME, $this->version)->reason)->toBe('ioncube_reload_failed');

    $this->withToken($this->token)->getJson("/api/php/versions/{$this->version}/ioncube")
        ->assertOk()
        ->assertJsonPath('ioncube.status', 'failed')
        ->assertJsonPath('ioncube.reason', 'ioncube_reload_failed')
        ->assertJsonPath('ioncube.message', 'PHP could not be reloaded. The changes may not yet be active. Any recovery copies have been retained.');
});

it('gives a sentence, not a code, for a cause with no ionCube wording', function (string $reason) {
    // `worker` (the job died) and `install_failed` (rows from before causes
    // were kept) have no errors/php sentence of their own.
    fakeIonCube(['existing' => true]);
    $tracker = app(InstallTracker::class);
    $tracker->start(InstallIonCubeLoader::RUNTIME, $this->version);
    $tracker->fail(InstallIonCubeLoader::RUNTIME, $this->version, null, $reason, 'ref-1');

    $message = $this->withToken($this->token)->getJson("/api/php/versions/{$this->version}/ioncube")
        ->assertOk()->json('ioncube.message');

    expect($message)->toBeString()->not->toBe('')->not->toContain($reason)->not->toContain('errors/php');
})->with(['worker', 'install_failed']);

it('says nothing when the last run did not fail', function () {
    fakeIonCube(['existing' => true]);

    // A retry: failed once, running again. The old failure is not the news.
    $tracker = app(InstallTracker::class);
    $tracker->start(InstallIonCubeLoader::RUNTIME, $this->version);
    $tracker->fail(InstallIonCubeLoader::RUNTIME, $this->version, null, 'ioncube_download_failed', 'ref-1');
    $tracker->start(InstallIonCubeLoader::RUNTIME, $this->version);

    $this->withToken($this->token)->getJson("/api/php/versions/{$this->version}/ioncube")
        ->assertOk()->assertJsonPath('ioncube.message', null);
});

it('fails removal on reload error instead of returning success', function () {
    fakeIonCube(['existing' => true, 'fail' => fn ($args) => ($args[0] ?? '') === 'systemctl']);
    $this->withToken($this->token)->deleteJson("/api/php/versions/{$this->version}/ioncube")
        ->assertStatus(500)->assertJsonPath('message', __('errors/php.ioncube_reload_failed'));
    $this->assertDatabaseMissing('activity_logs', ['type' => 'php', 'action' => 'ioncube_removed']);
});

it('authorizes removal and validates before reloading', function () {
    $runs = fakeIonCube();
    $this->withToken(User::factory()->create()->createToken('t')->plainTextToken)
        ->deleteJson("/api/php/versions/{$this->version}/ioncube")->assertForbidden();
    expect($runs)->toHaveCount(0);
});

it('validates removal before reloading', function () {
    $runs = fakeIonCube();
    $this->withToken($this->token)->deleteJson("/api/php/versions/{$this->version}/ioncube")->assertOk();
    $commands = collect($runs)->values();
    $test = $commands->search(fn ($c) => str_contains($c, 'php-fpm') && str_ends_with($c, '-t'));
    $reload = $commands->search(fn ($c) => str_contains($c, 'systemctl reload'));
    expect($test)->not->toBeFalse()->and($reload)->toBeGreaterThan($test);
});

it('aborts oversized transfers with or without a content length and cleans the sink', function (int $total, int $received) {
    fakeIonCube();
    config(['server.ioncube.max_bytes' => 10]);
    $sink = null;
    $continued = false;
    Http::fake(function ($request, $options) use (&$sink, &$continued, $total, $received) {
        $sink = $options['sink'];
        file_put_contents($sink, 'partial');
        expect($options['allow_redirects']['protocols'])->toBe(['https'])
            ->and($options['protocols'])->toBe(['https']);
        ($options['progress'])($total, $received, 0, 0);
        $continued = true;

        return Http::response('oversized');
    });
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_download_failed'))
        ->and($continued)->toBeFalse()
        ->and($sink)->not->toBeNull()->and(file_exists(dirname($sink)))->toBeFalse();
})->with([[11, 0], [0, 11]]);

it('discovers the loader only before changing PHP configuration', function () {
    $runs = fakeIonCube();
    app(IonCubeLoader::class)->install($this->version);
    $commands = collect($runs)->values();
    $firstWrite = $commands->search(fn ($c) => str_starts_with($c, 'install -m'));
    expect($commands->filter(fn ($c) => str_contains($c, ' -r ')))->toHaveCount(2)
        ->and($commands->slice($firstWrite)->filter(fn ($c) => str_contains($c, ' -r ')))->toBeEmpty();
});

it('restores the original file bytes after a failed reinstall', function () {
    $loader = "{$this->phpDir}/ioncube_loader_lin_{$this->version}.so";
    file_put_contents($loader, 'old-loader');
    $inis = [];
    foreach (['cli', 'fpm'] as $sapi) {
        $ini = "{$this->phpDir}/{$this->version}/{$sapi}/conf.d/01-ioncube.ini";
        file_put_contents($ini, "; old {$sapi} configuration\nzend_extension={$loader}\n");
        $inis[$ini] = file_get_contents($ini);
    }
    fakeIonCube(['real_files' => true, 'extension_dir' => $this->phpDir, 'config_test' => false]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_config_test_failed'))
        ->and(file_get_contents($loader))->toBe('old-loader');
    foreach ($inis as $ini => $contents) {
        expect(file_get_contents($ini))->toBe($contents);
    }
    expect(glob($loader.'.panel-ioncube-*.bak'))->toBeEmpty();
});

it('refuses an HTTP redirect through the real Guzzle middleware', function () {
    fakeIonCube();
    $history = [];
    Http::fake(function ($request, $options) use (&$history) {
        $handler = new MockHandler([
            new Response(302, ['Location' => 'http://insecure.example/loader']),
            new Response(200, [], 'must-not-be-read'),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        // Exercise the installer's actual transport policy, not a copy of it.
        $client->get('https://vendor.example/loader', [
            'protocols' => $options['protocols'],
            'allow_redirects' => $options['allow_redirects'],
        ]);

        return Http::response('unexpected download');
    });
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_download_failed'))
        ->and($history)->toHaveCount(1);
});

it('tests LSPHP before restarting OpenLiteSpeed and preserves backups on restart failure', function () {
    config([
        'server.php_stacks.lsphp.binary_candidates' => ['/usr/bin/php'.$this->version],
        'server.php_stacks.lsphp.ini_path' => "{$this->phpDir}/{$this->version}/litespeed/php.ini",
        'server.php_stacks.lsphp.sapis' => ['litespeed'],
        'server.php_stacks.lsphp.reload_command' => ['/usr/local/lsws/bin/lswsctrl', 'restart'],
    ]);
    $runs = fakeIonCube(['existing' => true, 'fail' => fn ($args) => str_ends_with($args[0] ?? '', 'lswsctrl')]);
    $service = new IonCubeLoader(app(ServerOps::class), app(ManagedFile::class), app(LsphpPhpStack::class));
    $error = ionCubeError(fn () => $service->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_reload_failed'));
    $commands = collect($runs)->values();
    $test = $commands->search(fn ($c) => str_contains($c, '/litespeed/php.ini -v'));
    $reload = $commands->search(fn ($c) => str_contains($c, 'lswsctrl restart'));
    expect($test)->not->toBeFalse()->and($reload)->toBeGreaterThan($test)
        ->and($commands->filter(fn ($c) => str_starts_with($c, 'rm -f') && str_ends_with($c, '.bak')))->toBeEmpty();
});

it('restores a failed INI write without reloading', function () {
    $runs = fakeIonCube(['existing' => true, 'fail' => fn ($args) => ($args[0] ?? '') === 'tee']);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_install_failed'))
        ->and(collect($runs)->filter(fn ($c) => preg_match('/^cp -p \S+\.bak /', $c)))->toHaveCount(3)
        ->and(collect($runs)->filter(fn ($c) => str_contains($c, 'systemctl reload')))->toBeEmpty();
});

it('does not remove the binary when the removal configuration test fails', function () {
    $runs = fakeIonCube(['existing' => true, 'config_test' => false]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->remove($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_config_test_failed'))
        ->and(collect($runs)->filter(fn ($c) => str_starts_with($c, 'rm -f') && str_ends_with($c, '.so')))->toBeEmpty()
        ->and(collect($runs)->filter(fn ($c) => str_contains($c, 'systemctl reload')))->toBeEmpty();
});

it('restores removed INIs if binary deletion fails', function () {
    $runs = fakeIonCube(['existing' => true, 'fail' => fn ($args) => ($args[0] ?? '') === 'rm' && str_ends_with($args[2] ?? '', '.so')]);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->remove($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_removal_failed'))
        ->and(collect($runs)->filter(fn ($c) => preg_match('/^cp -p \S+\.bak /', $c)))->toHaveCount(3)
        ->and(collect($runs)->filter(fn ($c) => str_contains($c, 'systemctl reload')))->toBeEmpty();
});

it('refuses uncertain file state rather than treating a failed probe as absence', function () {
    $runs = fakeIonCube(['fail' => fn ($args) => ($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-e']);
    $error = ionCubeError(fn () => app(IonCubeLoader::class)->install($this->version));
    expect($error['message'])->toBe(__('errors/php.ioncube_discovery_failed'))
        ->and(collect($runs)->filter(fn ($c) => preg_match('/^(install|tee|cp|rm) /', $c)))->toBeEmpty();
});

/*
| Where the ini goes.
|
| 🔴 Measured from LiteSpeed's shipped package on 2026-09-18, after a real
| OpenLiteSpeed server reported ionCube as not installed. `lsphp85` on noble is
| built with:
|
|   --with-config-file-path=/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/
|   --with-config-file-scan-dir=/usr/local/lsws/lsphp85/etc/php/8.5/mods-available/
|
| read out of the binary's own configure line, and the package contains
| `litespeed/` and `mods-available/` and NO `conf.d` at all.
|
| The old code composed `sapiDir()."/conf.d"` — Debian's layout — which on that
| stack is wrong twice: absent, so `tee` failed and the install aborted; and
| unscanned, so merely creating it would have produced an install that reported
| success and never loaded. The second is the dangerous one, and it is what
| these tests exist to prevent coming back.
*/

it('writes the loader ini where LSPHP actually scans, not into a conf.d', function () {
    config([
        'server.php_stacks.lsphp.binary_candidates' => ['/usr/bin/php'.$this->version],
        'server.php_stacks.lsphp.ini_path' => "{$this->phpDir}/{$this->version}/litespeed/php.ini",
        'server.php_stacks.lsphp.sapis' => ['litespeed'],
    ]);

    $runs = fakeIonCube(['existing' => true]);
    $service = new IonCubeLoader(app(ServerOps::class), app(ManagedFile::class), app(LsphpPhpStack::class));

    $service->install($this->version);

    $written = collect($runs)->first(fn (string $c) => str_starts_with($c, 'tee ') && str_contains($c, 'ioncube'));

    // Sibling of the `litespeed/` ini directory, exactly as the real tree has
    // it: /usr/local/lsws/lsphp85/etc/php/8.5/mods-available.
    expect($written)->toContain("{$this->phpDir}/{$this->version}/mods-available/01-ioncube.ini")
        // Said explicitly: the old path must not survive anywhere. A test that
        // only checked the new one would still pass if both were written.
        ->and(collect($runs)->filter(fn (string $c) => str_contains($c, 'conf.d')))->toBeEmpty();
});

it('keeps writing to conf.d on php-fpm, where that is the scanned directory', function () {
    // The other half of the fix. Changing where an extension is enabled is
    // only safe if the servers that work today do not move, so "nothing
    // changed" is asserted rather than assumed.
    $runs = fakeIonCube(['existing' => true]);

    app(IonCubeLoader::class)->install($this->version);

    $written = collect($runs)->filter(fn (string $c) => str_starts_with($c, 'tee ') && str_contains($c, 'ioncube'));

    expect($written)->not->toBeEmpty()
        ->and($written->every(fn (string $c) => str_contains($c, "/{$this->version}/")
            && str_contains($c, '/conf.d/01-ioncube.ini')))->toBeTrue();
});

it('creates the scan directory before writing into it', function () {
    // `tee` does not create parents. Ordering, not just presence: a mkdir that
    // ran after the write would satisfy a "did it mkdir" assertion and fix
    // nothing.
    $runs = fakeIonCube(['existing' => true]);

    app(IonCubeLoader::class)->install($this->version);

    $commands = collect($runs)->values();
    $mkdir = $commands->search(fn (string $c) => str_starts_with($c, 'mkdir -p') && str_contains($c, 'conf.d'));
    $write = $commands->search(fn (string $c) => str_starts_with($c, 'tee ') && str_contains($c, 'ioncube'));

    expect($mkdir)->not->toBeFalse()
        ->and($write)->not->toBeFalse()
        ->and($mkdir)->toBeLessThan($write);
});

it('reports not-installed without writing a failure to the error log', function () {
    // A real OpenLiteSpeed server produced this entry, and it was the only
    // thing the operator could see: "Server operation failed." with a
    // reference, for `test -f` answering exit 1 — the ordinary answer on every
    // server that has not installed ionCube.
    //
    // Log::listen rather than Log::shouldReceive: mocking the facade turns
    // every unrelated log call in the request into a test failure.
    config([
        'server.php_stacks.lsphp.binary_candidates' => ['/usr/bin/php'.$this->version],
        'server.php_stacks.lsphp.ini_path' => "{$this->phpDir}/{$this->version}/litespeed/php.ini",
        'server.php_stacks.lsphp.sapis' => ['litespeed'],
    ]);

    Process::fake(fn () => Process::result(exitCode: 1));

    $levels = [];
    Log::listen(function ($message) use (&$levels) {
        if (($message->context['op'] ?? null) === 'ioncube_status') {
            $levels[] = $message->level;
        }
    });

    $service = new IonCubeLoader(app(ServerOps::class), app(ManagedFile::class), app(LsphpPhpStack::class));

    expect($service->status($this->version)['installed'])->toBeFalse()
        ->and($levels)->not->toBeEmpty()
        ->and(collect($levels)->contains('error'))->toBeFalse();
});
