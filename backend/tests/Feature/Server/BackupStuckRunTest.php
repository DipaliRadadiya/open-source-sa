<?php

use App\Enums\BackupStatus;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Server\Backups\StaleBackupReaper;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'name' => 'Offsite', 'endpoint' => '', 'region' => 'us-east-1',
        'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's',
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
