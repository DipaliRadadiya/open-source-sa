<?php

namespace Tests\Feature\Server;

use App\Exceptions\Server\Application\FileOperationException;
use App\Jobs\MeasureApplicationSize;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\FileBrowser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // No admin bypass: the Administrator role only holds permissions that exist, so without the
    // seeder every route here answers 403.
    $this->seed(PermissionSeeder::class);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
    ]);
});

it('exposes directory_size_bytes in application resource when set', function () {
    $this->application->update(['directory_size_bytes' => 1024 * 1024 * 50]);

    $response = $this->getJson("/api/applications/{$this->application->id}");

    $response->assertOk()
        ->assertJsonPath('application.directory_size_bytes', 1024 * 1024 * 50);
});

it('returns a null size rather than dropping the field when never measured', function () {
    $this->application->update(['directory_size_bytes' => null]);

    // Present-and-null, not absent: the frontend must tell "not measured yet" from "size not
    // reported".
    $this->getJson("/api/applications/{$this->application->id}")
        ->assertOk()
        ->assertJsonPath('application.directory_size_bytes', null)
        ->assertJsonPath('application.directory_size_measured_at', null);
});

it('reports when the size was measured', function () {
    $this->application->update([
        'directory_size_bytes' => 2048,
        'directory_size_updated_at' => now()->subHours(3),
    ]);

    $response = $this->getJson("/api/applications/{$this->application->id}")->assertOk();

    expect($response->json('application.directory_size_bytes'))->toBe(2048)
        ->and($response->json('application.directory_size_measured_at'))->not->toBeNull()
        ->and($response->json('application.directory_size_measured_at_human'))->toContain('hours');
});

/**
 * `du` returns the size asked for; the directory probes that run first answer
 * "yes, a directory". Without those the browser 404s before it ever measures.
 */
function fakeDu(int $bytes): void
{
    Process::fake(function ($process) use ($bytes) {
        $command = $process->command;

        if (in_array('du', $command, true)) {
            return Process::result(output: $bytes."\t/home/x\n");
        }

        // `stat()` asks find for a type and a size.
        if (in_array('-printf', $command, true)) {
            return Process::result(output: "d\t4096");
        }

        return Process::result(exitCode: 0);
    });
}

describe('measuring on demand', function () {
    it('needs manage, not view', function () {
        fakeDu(42);
        $viewer = User::factory()->create();
        grantPermission($viewer, 'application', view: true, manage: false);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/applications/{$this->application->id}/directory-size")->assertForbidden();

        expect($this->application->fresh()->directory_size_bytes)->not->toBe(42);
    });

    it('measures the site root and remembers when', function () {
        fakeDu(5242880);

        $response = $this->postJson("/api/applications/{$this->application->id}/directory-size")
            ->assertOk();

        expect($response->json('directory_size.size'))->toBe(5242880)
            ->and($response->json('directory_size.measured_at'))->not->toBeNull();

        expect($this->application->fresh()->directory_size_bytes)->toBe(5242880)
            ->and($this->application->fresh()->directory_size_updated_at)->not->toBeNull();
    });

    it('re-measures rather than handing back the stored number', function () {
        $this->application->update([
            'directory_size_bytes' => 111,
            'directory_size_updated_at' => now()->subDay(),
        ]);

        fakeDu(999);

        // The whole point of the button: a cached value is what it is replacing.
        $this->postJson("/api/applications/{$this->application->id}/directory-size")
            ->assertOk()
            ->assertJsonPath('directory_size.size', 999);
    });

    it('does not let a subfolder redefine how big the site is', function () {
        $this->application->update(['directory_size_bytes' => 5000000]);

        fakeDu(42);

        // A folder's size must not overwrite the application's own figure.
        $this->getJson("/api/applications/{$this->application->id}/files/size?path=wp-content/uploads");

        expect($this->application->fresh()->directory_size_bytes)->toBe(5000000);
    });
});

describe('keeping the size current by itself', function () {
    it('re-measures after files change, without making the request wait', function () {
        Queue::fake();

        // The write path stats its target as a file (fakeDu answers 'd' for directory probes).
        Process::fake(function ($process) {
            if (in_array('-printf', $process->command, true)) {
                return Process::result(output: "f\t5");
            }

            return Process::result(exitCode: 0);
        });

        $this->putJson("/api/applications/{$this->application->id}/files/content", [
            'path' => 'notes.txt',
            'content' => 'hello',
        ])->assertOk();

        // Queued, not inline: `du` walks every inode and would slow saving one file.
        Queue::assertPushed(MeasureApplicationSize::class);
    });

    it('walks the site once for a burst of changes, not once per change', function () {
        fakeDu(1234);

        // Unique per application, so fifty deletions leave one job instead of fifty `du` walks.
        $first = new MeasureApplicationSize($this->application->id);
        $second = new MeasureApplicationSize($this->application->id);

        expect($first->uniqueId())->toBe($second->uniqueId())
            // The lock expires, so a worker killed mid-measure cannot block the site forever.
            ->and($first->uniqueFor())->toBeGreaterThan($first->timeout);
    });

    it('leaves the last known size alone when a measure fails', function () {
        $this->application->update([
            'directory_size_bytes' => 777,
            'directory_size_updated_at' => now()->subDay(),
        ]);

        Process::fake(fn ($process) => Process::result(exitCode: 1, errorOutput: 'du: cannot read'));

        (new MeasureApplicationSize($this->application->id))->handle(app(FileBrowser::class));

        // Better a number with an honest date than no number at all.
        expect($this->application->fresh()->directory_size_bytes)->toBe(777);
    });
});

it('measures a site once it has been provisioned', function () {
    // Without this, a one-click site nobody deployed or browsed would read "Not measured"
    // forever. Queued, not inline: `du` over a fresh WordPress is tens of thousands of inodes.
    Queue::fake();
    Process::fake();

    $application = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'status' => 'pending',
    ]);

    app(ApplicationProvisioner::class)->provision($application, skipInstaller: true);

    Queue::assertPushed(
        MeasureApplicationSize::class,
        fn (MeasureApplicationSize $job): bool => $job->applicationId === $application->id,
    );
});

it('queues a measurement for sites that have never had one, from the list', function () {
    // The backfill is how a site created before provision-time measuring gets a number:
    // browsing the list is enough.
    Queue::fake();

    Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'directory_size_bytes' => null,
        'directory_size_updated_at' => null,
    ]);

    $this->getJson('/api/applications')->assertOk();

    Queue::assertPushed(MeasureApplicationSize::class);
});

it('leaves an already-measured site alone', function () {
    // Otherwise every page view would re-walk every site, which is the inline `du` the queue avoids.
    Queue::fake();

    Application::query()->update([
        'directory_size_bytes' => 4096,
        'directory_size_updated_at' => now()->subDay(),
    ]);

    $this->getJson('/api/applications')->assertOk();

    Queue::assertNotPushed(MeasureApplicationSize::class);
});

it('caps how many one page view may queue', function () {
    // Hundreds of sites must not turn one page view into hundreds of walks; it fills in over visits.
    Queue::fake();

    Application::factory()
        ->count(MeasureApplicationSize::BACKFILL_LIMIT + 5)
        ->create([
            'system_user_id' => $this->systemUser->id,
            'directory_size_bytes' => null,
            'directory_size_updated_at' => null,
        ]);

    $this->getJson('/api/applications')->assertOk();

    Queue::assertPushed(MeasureApplicationSize::class, MeasureApplicationSize::BACKFILL_LIMIT);
});

it('backfills a site that has a measurement date but no figure', function () {
    // A row with a date but no bytes displays as "Not measured"; a guard that only looked at
    // the date would skip it and it could never recover.
    Queue::fake();

    Application::query()->update([
        'directory_size_bytes' => null,
        'directory_size_updated_at' => now()->subDay(),
    ]);

    $this->getJson('/api/applications')->assertOk();

    Queue::assertPushed(MeasureApplicationSize::class);
});

it('records the reference when a measurement fails, not an empty string', function () {
    // ServerOperationException has no message, only a reference (stderr goes to the server-ops
    // log), so logging getMessage() alone would write an empty detail.
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['reference'] === 'ref-123'
            && $context['exception'] === FileOperationException::class,
    );

    $this->mock(FileBrowser::class)
        ->shouldReceive('applicationSize')
        ->andThrow(new FileOperationException('ref-123'));

    (new MeasureApplicationSize($this->application->id))->handle(app(FileBrowser::class));
});

it('keeps a partial total when du could not read every file', function () {
    // `du` runs as the site user and exits 1 on any unreadable entry, yet still prints a correct
    // total for the rest: that figure must be kept, not discarded.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(
            output: "1048576\t/home/site/public_html\n",
            errorOutput: "du: cannot access '.../test/e2e/specs': Permission denied\n",
            exitCode: 1,
        ),
        // The root-exists and is-a-directory checks that run first.
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    app(FileBrowser::class)->applicationSize($this->application, refresh: true);

    expect($this->application->fresh()->directory_size_bytes)->toBe(1048576);
});

it('still fails when du produced no total at all', function () {
    // Zero is no answer: the previous figure and its date must survive.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(
            output: '',
            errorOutput: 'du: cannot read directory',
            exitCode: 1,
        ),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    $this->application->update(['directory_size_bytes' => 999, 'directory_size_updated_at' => now()->subDay()]);

    expect(fn () => app(FileBrowser::class)->applicationSize($this->application, refresh: true))
        ->toThrow(FileOperationException::class);

    expect($this->application->fresh()->directory_size_bytes)->toBe(999);
});

it('measures only the never-measured sites by default', function () {
    // The default fills in the gaps; walking every inode is a deliberate act.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(output: "2048\t/home/site"),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    $measured = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'directory_size_bytes' => 999,
        'directory_size_updated_at' => now()->subDay(),
    ]);

    $this->application->update(['directory_size_bytes' => null, 'directory_size_updated_at' => null]);

    $this->artisan('applications:measure-sizes')->assertExitCode(0);

    expect($this->application->fresh()->directory_size_bytes)->toBe(2048)
        ->and($measured->fresh()->directory_size_bytes)->toBe(999);
});

it('keeps going when one site cannot be measured, and says so', function () {
    // One unreadable checkout must not deny every other site its number.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(output: '', errorOutput: 'denied', exitCode: 1),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    $this->application->update(['directory_size_bytes' => null, 'directory_size_updated_at' => null]);

    $this->artisan('applications:measure-sizes')->assertExitCode(1);
});

it('measures the least recently measured sites first when sweeping', function () {
    // The rotation makes a per-minute schedule work: each tick takes a different slice, so no
    // site stays stale while the first few are walked forever.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(output: "512\t/home/site"),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    // Created newest-first on purpose, so ordering by id would not pass by coincidence.
    $newer = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'directory_size_bytes' => 2,
        'directory_size_updated_at' => now()->subHours(2),
    ]);
    $oldest = Application::factory()->create([
        'system_user_id' => $this->systemUser->id,
        'directory_size_bytes' => 1,
        'directory_size_updated_at' => now()->subDays(3),
    ]);
    $this->application->update(['directory_size_bytes' => 3, 'directory_size_updated_at' => now()]);

    $this->artisan('applications:measure-sizes --stale=60 --limit=1')->assertExitCode(0);

    // Only the oldest moved; the fresh one was not even a candidate.
    expect($oldest->fresh()->directory_size_bytes)->toBe(512)
        ->and($newer->fresh()->directory_size_bytes)->toBe(2)
        ->and($this->application->fresh()->directory_size_bytes)->toBe(3);
});

it('never measures more than the limit in one sweep', function () {
    // The bound that keeps one tick from walking every inode on the server.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(output: "512\t/home/site"),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    Application::factory()->count(6)->create([
        'system_user_id' => $this->systemUser->id,
        'directory_size_bytes' => 1,
        'directory_size_updated_at' => now()->subDays(2),
    ]);

    $this->artisan('applications:measure-sizes --stale=60 --limit=2')->assertExitCode(0);

    expect(Application::query()->where('directory_size_bytes', 512)->count())->toBe(2);
});

it('measures every site when the sweep is unbounded', function () {
    // The scheduled default: --stale=0 --limit=0 means every site, every minute. `--limit=0` must
    // read as "no ceiling", not zero rows, which would look like the schedule not running.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(output: "777\t/home/site"),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    Application::factory()->count(3)->create([
        'system_user_id' => $this->systemUser->id,
        'directory_size_bytes' => 1,
        // Measured seconds ago: a staleness threshold of 0 must still take it.
        'directory_size_updated_at' => now(),
    ]);

    $this->artisan('applications:measure-sizes --stale=0 --limit=0')->assertExitCode(0);

    expect(Application::query()->where('directory_size_bytes', 777)->count())
        ->toBe(Application::query()->count());
});

/*
 * A container site's size is mostly not in its directory: its compose file is a few KB while
 * its volumes hold the data, and the sites list sorts by this figure.
 *
 * The volumes are unreadable to the site user by design (`/var/lib/docker/volumes` is root-owned
 * at 0700), so this is a second measurement rather than a wider `du`.
 */

/** A container site, and a fake box that answers for its volumes. */
function containerSizeSite(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'system_user_id' => test()->systemUser->id,
        'site_type' => 'docker',
        'serving_profile' => 'docker',
    ], $overrides));
}

/**
 * @param  array<string, int>  $volumes  name => bytes
 */
function fakeSizeBox(int $directoryBytes, array $volumes, string $root = '/home'): void
{
    Process::fake(function ($process) use ($directoryBytes, $volumes) {
        $command = $process->command;

        if (in_array('find', $command, true)) {
            return Process::result(output: "d\t4096");
        }

        if (in_array('volume', $command, true) && in_array('ls', $command, true)) {
            $lines = [];
            foreach ($volumes as $name => $bytes) {
                $lines[] = $name."\t/var/lib/docker/volumes/{$name}/_data";
            }

            return Process::result(output: implode("\n", $lines)."\n");
        }

        if (in_array('du', $command, true)) {
            // The volume walk names mountpoints, the site walk names the document root.
            $paths = array_values(array_filter(
                $command,
                fn ($arg): bool => is_string($arg) && str_starts_with($arg, '/'),
            ));

            $lines = [];

            foreach ($paths as $path) {
                if (str_starts_with($path, '/var/lib/docker/volumes/')) {
                    $name = explode('/', substr($path, strlen('/var/lib/docker/volumes/')))[0];
                    $lines[] = ($volumes[$name] ?? 0)."\t".$path;

                    continue;
                }

                $lines[] = $directoryBytes."\t".$path;
            }

            return Process::result(output: implode("\n", $lines)."\n");
        }

        return Process::result(exitCode: 0);
    });
}

it('counts a container site as its directory plus its volumes', function () {
    $application = containerSizeSite();

    fakeSizeBox(6468, [
        'sv-app-'.$application->id.'_data' => 24_049_047,
        'sv-app-'.$application->id.'_plugins' => 164_246_710,
    ]);

    app(FileBrowser::class)->applicationSize($application, refresh: true);

    $fresh = $application->fresh();

    expect($fresh->volume_size_bytes)->toBe(188_295_757)
        // The total: the figure the list shows and sorts by.
        ->and($fresh->directory_size_bytes)->toBe(188_302_225);
});

it('leaves the volume figure null for a site that has no volumes to measure', function () {
    // Null, not 0: "does not apply" and "the volumes are empty" are different answers.
    Process::fake(fn ($process) => match (true) {
        in_array('du', $process->command, true) => Process::result(output: "1048576\t/home/site/public_html\n"),
        in_array('find', $process->command, true) => Process::result(output: "d\t4096"),
        default => Process::result(exitCode: 0),
    });

    app(FileBrowser::class)->applicationSize($this->application, refresh: true);

    $fresh = $this->application->fresh();

    expect($fresh->volume_size_bytes)->toBeNull()
        ->and($fresh->directory_size_bytes)->toBe(1048576);

    // And Docker was never asked: with no Docker installed that call fails.
    Process::assertNotRan(fn ($process): bool => in_array('volume', $process->command, true));
});

it('does not count another site\'s volumes as its own', function () {
    // `sv-app-2_` against `sv-app-20_data` is the collision that matters: the match is a prefix
    // including the underscore.
    $application = containerSizeSite();
    $otherId = $application->id + 1;

    fakeSizeBox(1000, [
        'sv-app-'.$application->id.'_mine' => 5_000,
        'sv-app-'.$otherId.'_theirs' => 900_000,
        'unrelated-volume' => 700_000,
    ]);

    app(FileBrowser::class)->applicationSize($application, refresh: true);

    expect($application->fresh()->volume_size_bytes)->toBe(5_000);
});

it('counts a volume attached by name as well as one the panel prefixed', function () {
    // Two sources, neither complete: `volume_mounts` holds volumes attached on the Container
    // screen (possibly unprefixed), the prefix covers those a one-click app or compose file brought.
    $application = containerSizeSite([
        'volume_mounts' => [['volume' => 'shared-uploads', 'path' => '/data']],
    ]);

    fakeSizeBox(1000, [
        'sv-app-'.$application->id.'_own' => 2_000,
        'shared-uploads' => 40_000,
        'somebody-elses' => 800_000,
    ]);

    app(FileBrowser::class)->applicationSize($application, refresh: true);

    expect($application->fresh()->volume_size_bytes)->toBe(42_000);
});

it('does not let a folder-size click drop the volumes back out of the total', function () {
    // `folderSize('/')` must not write into `directory_size_bytes`, or the root folder's size
    // would silently reset the site to its compose file's length.
    $application = containerSizeSite();

    fakeSizeBox(6468, ['sv-app-'.$application->id.'_data' => 500_000]);

    app(FileBrowser::class)->applicationSize($application, refresh: true);

    expect($application->fresh()->directory_size_bytes)->toBe(506_468);

    $folder = app(FileBrowser::class)->folderSize($application->fresh(), '/');

    // The folder answers about the folder; the site's own figure is untouched.
    expect($folder['size'])->toBe(6468)
        ->and($application->fresh()->directory_size_bytes)->toBe(506_468);
});
