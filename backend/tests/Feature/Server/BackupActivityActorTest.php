<?php

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Restores\RestoreRunner;
use Illuminate\Support\Str;

/*
 * Bug #46. Backups and restores run on the queue with nobody signed in, so
 * their Activity Log entries were written without the person who pressed the
 * button: anonymous in the panel-wide log and missing from that person's own.
 * And Undo, a restore of the safety backup, read as an ordinary restore.
 *
 * The runners are faked: what is under test is the entry each job writes,
 * not the backup or restore itself.
 */

beforeEach(function () {
    $this->user = User::factory()->create();

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
    ]);

    $destination = StorageDestination::create([
        'name' => 'Offsite',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $this->target = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'full',
        'retention_count' => 7,
        'frequency' => 'daily',
        'enabled' => true,
    ]);

    $this->backupOf = fn (bool $isSafety = false) => Backup::create([
        'backup_target_id' => $this->target->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'is_safety' => $isSafety,
        'status' => BackupStatus::Verified,
        'manifest' => ['key' => 'shop/'.uniqid().'.tar.gz'],
    ]);
});

function fakeBackupRunner(Backup $backup): void
{
    app()->instance(BackupRunner::class, Mockery::mock(BackupRunner::class, [
        'run' => $backup,
    ]));
}

function fakeRestoreRunner(RestoreStatus $status): void
{
    $runner = Mockery::mock(RestoreRunner::class);
    $runner->shouldReceive('run')->andReturnUsing(function (Restore $restore) use ($status) {
        $restore->update(['status' => $status, 'reason' => $status === RestoreStatus::Failed ? 'import_failed' : null]);

        return $restore;
    });
    app()->instance(RestoreRunner::class, $runner);
}

function runRestoreOf(Backup $backup, ?User $user): void
{
    $restore = Restore::create([
        'backup_id' => $backup->id,
        'application_id' => $backup->application_id,
        'user_id' => $user?->id,
        'type' => 'full',
        'status' => RestoreStatus::Pending,
        'reference' => (string) Str::uuid(),
    ]);

    app()->call([new RunRestore($restore->id, $backup->application_id), 'handle']);
}

it('records who pressed Back up now', function () {
    fakeBackupRunner(($this->backupOf)());

    app()->call([new RunBackup($this->target->id, $this->user->id), 'handle']);

    $entry = ActivityLog::where('type', 'backup')->where('action', 'completed')->sole();

    expect($entry->user_id)->toBe($this->user->id);
});

it('leaves a scheduled backup to System', function () {
    fakeBackupRunner(($this->backupOf)());

    app()->call([new RunBackup($this->target->id), 'handle']);

    expect(ActivityLog::where('type', 'backup')->where('action', 'completed')->sole()->user_id)->toBeNull();
});

it('records who pressed Restore', function () {
    fakeRestoreRunner(RestoreStatus::Succeeded);

    runRestoreOf(($this->backupOf)(), $this->user);

    $entry = ActivityLog::where('type', 'backup')->where('action', 'restored')->sole();

    expect($entry->user_id)->toBe($this->user->id);
});

it('records an Undo as an undo, not as another restore', function (RestoreStatus $status, string $action) {
    fakeRestoreRunner($status);

    runRestoreOf(($this->backupOf)(isSafety: true), $this->user);

    $entry = ActivityLog::where('type', 'backup')->sole();

    expect($entry->action)->toBe($action)
        ->and($entry->user_id)->toBe($this->user->id)
        ->and(__("activity.backup.{$action}", $entry->properties))->toContain('Shop');
})->with([
    'succeeded' => [RestoreStatus::Succeeded, 'undone'],
    'failed' => [RestoreStatus::Failed, 'undo_failed'],
]);
