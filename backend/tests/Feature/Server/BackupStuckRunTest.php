<?php

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Backups\BackupStopCause;
use App\Services\Server\Backups\StaleBackupReaper;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * Two failures that look unrelated on screen and share one cause: a backup job
 * that dies without getting to tell anyone.
 *
 *  - Handled failures record the attempt, so the schedule moves on. A job that
 *    is killed outright did not, so the target stayed due and fired again on
 *    the next tick — a new failed row every minute, for as long as whatever
 *    killed it kept killing it.
 *  - The row it leaves behind sits at `running` forever, and every path that
 *    starts a backup refuses while one is in flight. One stranded row and the
 *    site is never backed up again, with nothing on screen saying so.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    grantPermission($this->user, 'app_backup', view: true, manage: true);

    $this->application = Application::factory()->create(['name' => 'Company Blog']);

    $this->destination = StorageDestination::create([
        'name' => 'Offsite',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $this->backupTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $this->destination->id,
        'type' => 'full',
        'retention_count' => 7,
        'frequency' => 'daily',
        'enabled' => true,
    ]);
});

/** A run in whatever state, aged by $minutes. */
function stuckRun(BackupStatus $status, int $minutes): Backup
{
    $backup = Backup::create([
        'backup_target_id' => test()->backupTarget->id,
        'application_id' => test()->application->id,
        'type' => 'full',
        'status' => $status,
        'started_at' => now()->subMinutes($minutes),
    ]);

    // created_at is set by the timestamps, and the reaper falls back to it for
    // a run that never started — age both so the fixture is unambiguous.
    $backup->forceFill(['created_at' => now()->subMinutes($minutes)])->save();

    return $backup->refresh();
}

describe('a job that dies outright', function () {

    /*
     * BackupRunner's catch block does this for every handled failure, five
     * lines from the hook that did not. Without it the target is due again on
     * the next tick and the failure repeats every minute.
     */
    it('records the attempt so the schedule stops firing every minute', function () {
        $before = $this->backupTarget->last_run_at;

        (new RunBackup($this->backupTarget->id))->failed(new RuntimeException('worker killed'));

        expect($before)->toBeNull()
            ->and($this->backupTarget->fresh()->last_run_at)->not->toBeNull()
            ->and($this->backupTarget->fresh()->isDue(now()))->toBeFalse();
    });

    it('still closes the row it left behind', function () {
        $backup = stuckRun(BackupStatus::Running, 0);

        (new RunBackup($this->backupTarget->id))->failed(new RuntimeException('worker killed'));

        expect($backup->fresh()->status)->toBe(BackupStatus::Failed)
            ->and($backup->fresh()->reason)->toBe('crashed');
    });

    /*
     * `crashed` is written by that hook and had no entry in the error list, so
     * the screen rendered the literal key. Every other value `reason` can hold
     * is a step key, and those were all translated — this one was invented in
     * the job and never followed up.
     */
    it('has prose for its reason, not a translation key', function () {
        // Reading one backup is the server-wide `backup` permission, not the
        // per-application one the write paths use.
        grantPermission($this->user, 'backup');

        $backup = stuckRun(BackupStatus::Running, 0);
        (new RunBackup($this->backupTarget->id))->failed(new RuntimeException('killed'));

        $payload = $this->getJson("/api/backups/{$backup->id}")->assertOk()->json('backup');

        expect($payload['reason'])->toBe('crashed')
            ->and($payload['reason_title'])->not->toContain('backup.errors')
            ->and($payload['reason_title'])->toContain('stopped unexpectedly');
    });
});

describe('a run stranded in flight', function () {

    /*
     * The bound is the job's own timeout plus the grace ExpiresUniqueLock
     * already uses for the queue lock. Past it, the worker has given up and the
     * lock is collectable, so nothing can still be writing to this row.
     */
    it('is closed out once nothing could still be working on it', function () {
        $stale = stuckRun(BackupStatus::Running, 24 * 60);

        expect(app(StaleBackupReaper::class)->reap($this->backupTarget))->toBe(1)
            ->and($stale->fresh()->status)->toBe(BackupStatus::Failed)
            ->and($stale->fresh()->reason)->toBe('abandoned')
            ->and($stale->fresh()->finished_at)->not->toBeNull();
    });

    /*
     * The half that matters more. Expiring early would let a second backup
     * start beside one that is genuinely running — two archives of the same
     * site competing for one disk and one archive key, which is exactly what
     * the unique lock exists to prevent.
     */
    it('is left alone while it could still be alive', function () {
        $live = stuckRun(BackupStatus::Running, 5);

        expect(app(StaleBackupReaper::class)->reap($this->backupTarget))->toBe(0)
            ->and($live->fresh()->status)->toBe(BackupStatus::Running);
    });

    it('counts a run that never started, not just one that stalled midway', function () {
        // Dispatched while the queue was down: no worker ever touched it, so
        // there is no started_at to measure from.
        $queued = stuckRun(BackupStatus::Pending, 24 * 60);
        $queued->forceFill(['started_at' => null])->save();

        expect(app(StaleBackupReaper::class)->reap($this->backupTarget))->toBe(1);
    });

    it('unblocks the site, so a backup can be started again', function () {
        Queue::fake();
        stuckRun(BackupStatus::Running, 24 * 60);

        $this->postJson("/api/applications/{$this->application->id}/backups")
            ->assertStatus(202);

        Queue::assertPushed(RunBackup::class);
    });

    it('still refuses while a real run is in progress', function () {
        Queue::fake();
        stuckRun(BackupStatus::Running, 5);

        $this->postJson("/api/applications/{$this->application->id}/backups")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'A backup for this application is already running.']);

        Queue::assertNothingPushed();
    });
});

describe('POST /backups/{backup}/clear', function () {

    it('tells the screen which rows the button will accept (BK-B)', function () {
        grantPermission($this->user, 'backup');
        $stale = stuckRun(BackupStatus::Running, 24 * 60);
        $live = stuckRun(BackupStatus::Running, 5);

        $this->getJson("/api/backups/{$stale->id}")
            ->assertJsonPath('backup.is_stale', true)
            ->assertJsonPath('backup.clearable', true);
        $this->getJson("/api/backups/{$live->id}")
            ->assertJsonPath('backup.is_stale', false)
            ->assertJsonPath('backup.clearable', false);
    });

    it('closes a stranded run on demand rather than after an hour', function () {
        $stale = stuckRun(BackupStatus::Running, 24 * 60);

        $this->postJson("/api/backups/{$stale->id}/clear")
            ->assertOk()
            ->assertJsonPath('backup.status', 'failed')
            ->assertJsonPath('backup.reason', 'abandoned');

        $this->assertDatabaseHas('activity_logs', [
            'type' => 'backup',
            'action' => 'cleared',
            'user_id' => $this->user->id,
        ]);
    });

    /*
     * Nothing here can stop a job already executing on a worker. Marking it
     * failed would leave the row saying one thing and the process doing
     * another, and free the guard for a second backup alongside the first.
     */
    it('refuses a run that could still be executing, and says when it clears itself', function () {
        $live = stuckRun(BackupStatus::Running, 5);

        $this->postJson("/api/backups/{$live->id}/clear")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('backup.errors.clear_too_soon', [
                'minutes' => (int) ceil(StaleBackupReaper::staleAfterSeconds() / 60),
            ])]);

        expect($live->fresh()->status)->toBe(BackupStatus::Running);
    });

    it('refuses a backup that already finished', function () {
        $done = stuckRun(BackupStatus::Verified, 24 * 60);

        $this->postJson("/api/backups/{$done->id}/clear")->assertStatus(422);

        expect($done->fresh()->status)->toBe(BackupStatus::Verified);
    });

    it('needs permission to manage this application\'s backups', function () {
        $stale = stuckRun(BackupStatus::Running, 24 * 60);

        $other = User::factory()->create();
        $this->actingAs($other);

        $this->postJson("/api/backups/{$stale->id}/clear")->assertForbidden();
    });
});

describe('while a restore of the same site is in progress', function () {
    // A backup taken mid-restore captures a half-restored site, is marked
    // verified, and lets retention prune an older good backup to make room
    // for it (found in code review 2026-09-29).
    beforeEach(function () {
        Restore::forceCreate([
            'application_id' => $this->application->id,
            'status' => RestoreStatus::Running,
            'type' => 'full',
        ]);
    });

    it('refuses "back up now" with a message that says why', function () {
        Queue::fake();

        $this->postJson("/api/applications/{$this->application->id}/backups")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'A restore for this application is already running.']);

        Queue::assertNothingPushed();
    });

    it('skips a scheduled run rather than backing up a half-restored site', function () {
        (new RunBackup($this->backupTarget->id))->handle(app(BackupRunner::class), app(ActivityLogger::class));

        expect(Backup::where('backup_target_id', $this->backupTarget->id)->count())->toBe(0);
    });
});

it('skips a scheduled run while a staging push is overwriting the site', function () {
    Cache::put('staging-push-running:'.$this->application->id, true, 60);

    (new RunBackup($this->backupTarget->id))->handle(app(BackupRunner::class), app(ActivityLogger::class));

    expect(Backup::where('backup_target_id', $this->backupTarget->id)->count())->toBe(0);
});

/*
 * 2026-10-29, Dipali: after "Clear" a stuck backup only ever said "never
 * reported back", so neither the user nor support could tell what happened.
 * The step it was on and the likely cause are now kept and spelled out.
 */
describe('saying where a dead run stopped and why', function () {

    /** The box's boot time and the worker's start, as the cause reads them. */
    function stopClock(?string $bootedAt, ?string $workerStartedAt): void
    {
        $stat = tempnam(sys_get_temp_dir(), 'stat');
        file_put_contents($stat, $bootedAt === null ? "cpu 1 2 3\n" : "cpu 1 2 3\nbtime ".strtotime($bootedAt)."\nprocesses 9\n");
        config(['server.backups.boot_stat_path' => $stat]);

        Process::fake(fn ($process) => ($process->command[0] ?? '') === 'systemctl'
            ? Process::result(output: $workerStartedAt === null ? "\n" : date('D Y-m-d H:i:s', strtotime($workerStartedAt))." UTC\n")
            : Process::result(exitCode: 1));
    }

    function runOnStep(string $step, int $minutes): Backup
    {
        $backup = stuckRun(BackupStatus::Running, $minutes);
        $backup->update(['reason' => $step]);

        return $backup->refresh();
    }

    it('keeps the step a cleared run was on, and finds a restarted worker', function () {
        grantPermission($this->user, 'backup');
        stopClock(bootedAt: '-30 days', workerStartedAt: '-2 hours');
        $stale = runOnStep('upload_artifact', 24 * 60);

        $payload = $this->postJson("/api/backups/{$stale->id}/clear")->assertOk()->json('backup');

        expect($payload['reason'])->toBe('abandoned')
            ->and($payload['stopped_step'])->toBe('upload_artifact')
            ->and($payload['stopped_cause'])->toBe(BackupStopCause::WORKER_RESTARTED)
            ->and($payload['reason_title'])->toBe(
                'This backup stopped before it finished, while: Uploading to storage. '
                ."The panel's background worker was restarted while it was running, for example by a panel update. "
                .'Nothing from this run can be used. Click Retry to take a new backup.'
            );
    });

    it('says the server was restarted when it booted after the run started', function () {
        stopClock(bootedAt: '-3 hours', workerStartedAt: '-3 hours');
        $stale = runOnStep('archive_files', 24 * 60);

        app(StaleBackupReaper::class)->reap($this->backupTarget);

        expect($stale->fresh()->stopped_step)->toBe('archive_files')
            ->and($stale->fresh()->stopped_cause)->toBe(BackupStopCause::SERVER_RESTARTED);
    });

    it('says the cause is unknown rather than guessing when nothing restarted', function () {
        grantPermission($this->user, 'backup');
        stopClock(bootedAt: '-30 days', workerStartedAt: '-30 days');
        $stale = runOnStep('dump_database', 24 * 60);

        app(StaleBackupReaper::class)->reap($this->backupTarget);
        $payload = $this->getJson("/api/backups/{$stale->id}")->assertOk()->json('backup');

        expect($payload['stopped_cause'])->toBeNull()
            ->and($payload['reason_title'])->toContain('while: Dumping the database.')
            ->and($payload['reason_title'])->toContain('The cause could not be found.');
    });

    it('says a crashed job hit its time limit', function () {
        stopClock(bootedAt: '-30 days', workerStartedAt: '-30 days');
        $backup = runOnStep('upload_artifact', 10);

        (new RunBackup($this->backupTarget->id))->failed(new TimeoutExceededException('timed out'));

        expect($backup->fresh()->reason)->toBe('crashed')
            ->and($backup->fresh()->stopped_step)->toBe('upload_artifact')
            ->and($backup->fresh()->stopped_cause)->toBe(BackupStopCause::TIMED_OUT);
    });

    it('says a crashed job died with its worker', function () {
        stopClock(bootedAt: '-30 days', workerStartedAt: '-1 minute');
        $backup = runOnStep('verify_artifact', 10);

        (new RunBackup($this->backupTarget->id))->failed(new RuntimeException('killed'));

        expect($backup->fresh()->stopped_step)->toBe('verify_artifact')
            ->and($backup->fresh()->stopped_cause)->toBe(BackupStopCause::WORKER_RESTARTED);
    });

    it('says it stopped before any step when it never started one', function () {
        grantPermission($this->user, 'backup');
        stopClock(bootedAt: '-1 hour', workerStartedAt: '-1 hour');
        $stale = stuckRun(BackupStatus::Pending, 24 * 60);

        app(StaleBackupReaper::class)->reap($this->backupTarget);
        $payload = $this->getJson("/api/backups/{$stale->id}")->assertOk()->json('backup');

        expect($payload['stopped_step'])->toBeNull()
            ->and($payload['reason_title'])->toStartWith('It stopped before its first step. The server was restarted');
    });

    it('leaves rows closed before this change with their old sentence', function () {
        grantPermission($this->user, 'backup');
        $old = stuckRun(BackupStatus::Failed, 60);
        $old->update(['reason' => 'abandoned']);

        $this->getJson("/api/backups/{$old->id}")
            ->assertJsonPath('backup.stopped_step', null)
            ->assertJsonPath('backup.reason_title', __('backup.errors.abandoned'));
    });

    it('knows nothing when the box will not say', function () {
        stopClock(bootedAt: null, workerStartedAt: null);

        expect(app(BackupStopCause::class)->for(stuckRun(BackupStatus::Running, 60)))->toBeNull();
    });

    it('has every new sentence in every language', function () {
        foreach (['en', 'de', 'es', 'fr', 'hi', 'ja', 'pt', 'ru'] as $locale) {
            $keys = [
                'backup.steps.check_local_space', 'backup.errors.stopped_at', 'backup.errors.stopped_before_steps',
                'backup.errors.stopped_unknown', 'backup.errors.stopped_retry',
                ...array_map(fn ($c) => 'backup.errors.stopped_cause.'.$c, [BackupStopCause::TIMED_OUT, BackupStopCause::SERVER_RESTARTED, BackupStopCause::WORKER_RESTARTED]),
                ...array_map(fn ($s) => 'backup.steps.'.$s, StaleBackupReaper::STEPS),
            ];

            foreach ($keys as $key) {
                expect(__($key, [], $locale))->not->toBe($key, "{$locale}: {$key}");
            }
        }
    });
});
