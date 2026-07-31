<?php

use App\Enums\InstallStatus;
use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Jobs\InstallPhpExtension;
use App\Jobs\InstallPhpVersion;
use App\Models\RuntimeInstall;
use App\Models\User;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\PhpExtensionManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Install progress for PHP and Node.
 *
 * The thing under test is a gap rather than a behaviour: versions are detected
 * from disk, so anything apt has not finished has nowhere to appear. These
 * assert it appears anyway, and — the part that actually bites in production —
 * that it stops appearing as `installing` when the work stops.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A fake /etc/php with one installed version, so nothing depends on what
    // this machine happens to have.
    $this->phpDir = sys_get_temp_dir().'/sv-oss-progress-'.getmypid();
    File::deleteDirectory($this->phpDir);
    File::makeDirectory("{$this->phpDir}/8.4/fpm", 0755, true);

    config(['server.php_dir' => $this->phpDir]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

function progressHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function phpOverview(): array
{
    return test()->withHeaders(progressHeaders())->getJson('/api/php')->json('php');
}

it('shows an installed version as ready', function () {
    $versions = collect(phpOverview()['versions']);

    expect($versions->firstWhere('version', '8.4'))
        ->toMatchArray(['status' => 'ready', 'message' => null, 'reason' => null]);
});

it('shows a queued version as installing, with no files on disk', function () {
    Queue::fake();

    $this->withHeaders(progressHeaders())
        ->postJson('/api/php/versions', ['version' => '8.3'])
        ->assertStatus(202);

    // The point of the feature: 8.3 has nothing under /etc/php, so before this
    // it could not appear in the list at all.
    $row = collect(phpOverview()['versions'])->firstWhere('version', '8.3');

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('installing')
        ->and($row['started_at'])->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/');
});

it('records the install before dispatching, so an immediate poll sees it', function () {
    Queue::fake();

    // If the row were created inside the job, a client polling between the 202
    // and the worker picking the job up would see nothing — the exact blind
    // window this feature exists to close.
    $this->withHeaders(progressHeaders())->postJson('/api/php/versions', ['version' => '8.3']);

    Queue::assertPushed(InstallPhpVersion::class);
    expect(RuntimeInstall::query()->where('version', '8.3')->first()?->status)
        ->toBe(InstallStatus::Installing);
});

it('stops offering a version that is already installing', function () {
    Queue::fake();
    $this->withHeaders(progressHeaders())->postJson('/api/php/versions', ['version' => '8.3']);

    // Otherwise the button starts a second apt run for the same package.
    expect(collect(phpOverview()['installable'])->pluck('version'))->not->toContain('8.3');
});

it('reports a failure with a localized message and a reference', function () {
    app(InstallTracker::class)->start('php', '8.3');
    app(InstallTracker::class)->fail('php', '8.3', null, 'package_not_found', 'ref-123');

    $row = collect(phpOverview()['versions'])->firstWhere('version', '8.3');

    expect($row['status'])->toBe('failed')
        ->and($row['reason'])->toBe('package_not_found')
        ->and($row['reference'])->toBe('ref-123')
        ->and($row['message'])->toContain('No package for 8.3');
});

it('renders the failure in the viewer locale, not the installer one', function () {
    app(InstallTracker::class)->start('php', '8.3');
    app(InstallTracker::class)->fail('php', '8.3', null, 'no_space', 'ref-1');

    $row = collect(
        $this->withHeaders(progressHeaders() + ['Accept-Language' => 'de'])
            ->getJson('/api/php')->json('php.versions')
    )->firstWhere('version', '8.3');

    expect($row['message'])->toBe('Auf dem Server ist kein Speicherplatz mehr frei.');
});

it('falls back to the unknown message for a reason it has no wording for', function () {
    app(InstallTracker::class)->start('php', '8.3');
    app(InstallTracker::class)->fail('php', '8.3', null, 'something_new', 'ref-1');

    $row = collect(phpOverview()['versions'])->firstWhere('version', '8.3');

    // A missing translation must never surface as the key itself.
    expect($row['message'])->not->toContain('runtime.')
        ->and($row['message'])->toContain('failed');
});

it('clears the row on success, so ready comes from the disk', function () {
    $tracker = app(InstallTracker::class);
    $tracker->start('php', '8.4');
    $tracker->succeed('php', '8.4');

    expect(RuntimeInstall::query()->count())->toBe(0)
        ->and(collect(phpOverview()['versions'])->firstWhere('version', '8.4')['status'])->toBe('ready');
});

it('marks a stranded install failed when the worker dies', function () {
    app(InstallTracker::class)->start('php', '8.3');

    (new InstallPhpVersion('8.3'))->failed(null);

    $row = collect(phpOverview()['versions'])->firstWhere('version', '8.3');

    // Without the failed() hook this sits at `installing` forever and the
    // screen spins on something that stopped running.
    expect($row['status'])->toBe('failed')
        ->and($row['reason'])->toBe('worker');
});

it('does not let a dead worker overwrite a real reason', function () {
    $tracker = app(InstallTracker::class);
    $tracker->start('php', '8.3');
    $tracker->fail('php', '8.3', null, 'package_not_found', 'ref-9');

    (new InstallPhpVersion('8.3'))->failed(null);

    expect(RuntimeInstall::query()->where('version', '8.3')->first()->reason)
        ->toBe('package_not_found');
});

it('retrying clears the previous failure', function () {
    $tracker = app(InstallTracker::class);
    $tracker->start('php', '8.3');
    $tracker->fail('php', '8.3', null, 'network', 'ref-1');
    $tracker->start('php', '8.3');

    $row = RuntimeInstall::query()->where('version', '8.3')->first();

    expect($row->status)->toBe(InstallStatus::Installing)
        ->and($row->reason)->toBeNull()
        ->and($row->reference)->toBeNull();
});

describe('extensions', function () {
    it('marks a queued extension as installing and leaves the rest ready', function () {
        // apt-cache is the only source of the catalog here; everything else
        // returns nothing, so the rows are exactly these two.
        Process::fake(fn ($process) => ($process->command[0] ?? '') === 'apt-cache'
            ? Process::result(output: "php8.4-redis - Redis\nphp8.4-gd - GD\n")
            : Process::result(output: ''));

        app(InstallTracker::class)->start('php', '8.4', 'redis');

        $rows = collect(app(PhpExtensionManager::class)->catalog('8.4'));

        // `status` is the state of the *operation*. `installed`/`enabled` keep
        // meaning what they always did, so a never-installed extension is
        // `ready` — nothing in flight — rather than contradicting them.
        expect($rows->firstWhere('name', 'redis')['status'])->toBe('installing')
            ->and($rows->firstWhere('name', 'gd')['status'])->toBe('ready')
            ->and($rows->firstWhere('name', 'gd')['installed'])->toBeFalse();
    });

    it('records the extension install before dispatching', function () {
        Queue::fake();

        app(InstallTracker::class)->start('php', '8.4', 'redis');
        InstallPhpExtension::dispatch('8.4', 'redis');

        Queue::assertPushed(InstallPhpExtension::class);
        expect(app(InstallTracker::class)->extensions('php', '8.4')->has('redis'))->toBeTrue();
    });

    it('keeps version and extension rows apart', function () {
        $tracker = app(InstallTracker::class);
        $tracker->start('php', '8.3');
        $tracker->start('php', '8.3', 'redis');

        // The version row must not be picked up as an extension of itself.
        expect($tracker->versions('php')->keys()->all())->toBe(['8.3'])
            ->and($tracker->extensions('php', '8.3')->keys()->all())->toBe(['redis']);
    });

    it('switches an extension on after installing it, rather than trusting postinst', function () {
        $runs = new ArrayObject;

        Process::fake(function ($process) use ($runs) {
            $runs[] = $process->command;

            return Process::result(output: ($process->command[0] ?? '') === 'apt-cache'
                ? "php8.4-redis - Redis\n"
                : '');
        });

        app(PhpExtensionManager::class)->install('8.4', 'redis');

        // Debian's postinst normally enables the module, but nothing verified
        // it while the job went on to log `extension_enabled`. The claim and
        // the state have to agree.
        expect(collect($runs))
            ->toContain(['apt-get', 'install', '-y', '--no-install-recommends', 'php8.4-redis'])
            ->toContain(['/usr/sbin/phpenmod', '-v', '8.4', '-s', 'ALL', 'redis']);
    });

    it('reports installed-but-not-enabled as its own reason, not as a failed install', function () {
        Process::fake(function ($process) {
            $command = $process->command;

            if (($command[0] ?? '') === 'apt-cache') {
                return Process::result(output: "php8.4-redis - Redis\n");
            }

            // apt succeeds, phpenmod does not.
            return str_contains((string) ($command[0] ?? ''), 'phpenmod')
                ? Process::result(output: '', errorOutput: 'phpenmod: broken', exitCode: 1)
                : Process::result(output: '');
        });

        // "The install failed" would be wrong — apt already succeeded, and the
        // user would retry work that is done.
        expect(fn () => app(PhpExtensionManager::class)->install('8.4', 'redis'))
            ->toThrow(RuntimeInstallException::class);

        try {
            app(PhpExtensionManager::class)->install('8.4', 'redis');
        } catch (RuntimeInstallException $e) {
            expect($e->reason)->toBe('enable_failed');
        }
    });

    it('marks a stranded extension install failed when the worker dies', function () {
        app(InstallTracker::class)->start('php', '8.4', 'redis');

        (new InstallPhpExtension('8.4', 'redis'))->failed(null);

        expect(app(InstallTracker::class)->extensions('php', '8.4')->get('redis')->reason)
            ->toBe('worker');
    });
});

describe('failure classification', function () {
    it('reads a reason out of apt output', function (string $output, string $expected) {
        expect(app(InstallFailureClassifier::class)->classify('php', $output))->toBe($expected);
    })->with([
        ['E: Unable to locate package php8.3-fpm', 'package_not_found'],
        ['E: Could not get lock /var/lib/dpkg/lock-frontend', 'apt_lock'],
        ['Err:1 http://ppa.launchpad.net  Temporary failure resolving', 'network'],
        ['No space left on device', 'no_space'],
        ['something nobody has seen before', 'unknown'],
    ]);

    it('reads a reason out of fnm output', function () {
        expect(app(InstallFailureClassifier::class)->classify('node', "Can't find version '99'"))
            ->toBe('package_not_found');
    });

    it('never guesses when it does not recognise the output', function () {
        // Guessing a cause would be worse than admitting we do not know: the
        // reference still points at the real stderr.
        expect(app(InstallFailureClassifier::class)->classify('php', 'weird apt explosion'))
            ->toBe('unknown');
    });
});

describe('node', function () {
    it('shares the version list shape with php', function () {
        app(InstallTracker::class)->start('node', '22');

        $versions = collect(
            $this->withHeaders(progressHeaders())->getJson('/api/node')->json('node.versions')
        );

        $row = $versions->firstWhere('version', '22');

        expect($row['status'])->toBe('installing')
            ->and($row)->toHaveKeys(['started_at', 'started_at_human', 'reason', 'message', 'reference']);
    });

    it('keeps php and node rows separate', function () {
        $tracker = app(InstallTracker::class);
        $tracker->start('php', '8.3');
        $tracker->start('node', '8.3');

        // Same version string, different runtime — a shared table must not
        // let one runtime's install show up on the other's screen.
        expect($tracker->versions('php')->count())->toBe(1)
            ->and($tracker->versions('node')->count())->toBe(1);
    });
});
