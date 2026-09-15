<?php

use App\Exceptions\Server\Php\PhpConfigException;
use App\Jobs\InstallIonCubeLoader;
use App\Models\User;
use App\Services\Server\Php\IonCubeLoader;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    Process::fake(function ($process) use ($runs, $zts, $extract, $configTest, $body) {
        $command = (array) $process->command;
        $args = ($command[0] ?? '') === 'sudo' ? array_slice($command, 2) : $command;
        $runs[] = implode(' ', $args);

        // PHP answering questions about itself: the extension directory and
        // whether this build is thread-safe.
        if (str_contains((string) ($args[0] ?? ''), 'php') && in_array('-r', $args, true)) {
            $code = $args[array_search('-r', $args, true) + 1] ?? '';

            return str_contains($code, 'PHP_ZTS')
                ? Process::result(output: $zts ? '1' : '0')
                : Process::result(output: '/usr/lib/php/20240924');
        }

        if (($args[0] ?? '') === 'tar') {
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
