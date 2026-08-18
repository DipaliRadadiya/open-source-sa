<?php

namespace Tests\Feature\Server;

use App\Jobs\MeasureApplicationSize;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\FileBrowser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Feature access is role-based with no admin bypass, and the Administrator
    // role can only hold permissions that exist — so without the seeder the
    // admin has none of them and every route here answers 403.
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

    // Present-and-null, not absent: the frontend has to be able to tell "not
    // measured yet" from "this endpoint does not report size", and a column
    // that disappears from some rows can do neither.
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

        // Asking for one folder used to write its size into the application's,
        // so a glance at uploads permanently shrank the site.
        $this->getJson("/api/applications/{$this->application->id}/files/size?path=wp-content/uploads");

        expect($this->application->fresh()->directory_size_bytes)->toBe(5000000);
    });
});

describe('keeping the size current by itself', function () {
    it('re-measures after files change, without making the request wait', function () {
        Queue::fake();

        // The write path stats its target as a file; fakeDu answers 'd' for the
        // directory probes the other tests need.
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

        // Queued, not measured inline: `du` walks every inode, and paying that
        // in the request would make saving one file as slow as counting the
        // whole site.
        Queue::assertPushed(MeasureApplicationSize::class);
    });

    it('walks the site once for a burst of changes, not once per change', function () {
        fakeDu(1234);

        // Unique per application, so fifty deletions leave one job. Without
        // that, a bulk delete is a bulk `du`.
        $first = new MeasureApplicationSize($this->application->id);
        $second = new MeasureApplicationSize($this->application->id);

        expect($first->uniqueId())->toBe($second->uniqueId())
            // And the lock expires, so a worker killed mid-measure does not
            // stop this site ever being measured again.
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
    // Only a git deploy and the file browser ever wrote a size, so a one-click
    // site nobody had deployed or browsed was never counted — it read "Not
    // measured" in the sites list for as long as it existed. Queued rather
    // than inline: `du` over a fresh WordPress is forty thousand inodes and
    // does not belong inside provisioning.
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
    // The backfill is why an existing site ever gets a number: it was created
    // before anything measured on provision, and nobody is going to run a
    // command. Browsing the list is enough.
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
    // Otherwise every page view re-walks every site on the server, which is
    // the inline `du` this design exists to avoid, only queued.
    Queue::fake();

    Application::query()->update([
        'directory_size_bytes' => 4096,
        'directory_size_updated_at' => now()->subDay(),
    ]);

    $this->getJson('/api/applications')->assertOk();

    Queue::assertNotPushed(MeasureApplicationSize::class);
});

it('caps how many one page view may queue', function () {
    // A server with hundreds of sites must not turn one page view into
    // hundreds of directory walks. It fills in over a few visits instead.
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
