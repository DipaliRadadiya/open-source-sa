<?php

use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use App\Services\Server\Disk\DiskSpaceCheck;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/*
 * 2026-10-29 (Dipali): before an action that writes a lot to the disk is
 * accepted, the panel asks whether it fits, and refuses there and then with
 * the numbers — nothing queued, nothing changed.
 */

beforeEach(function () {
    Queue::fake();
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $user = SystemUser::create(['username' => 'spaceuser', 'home_path' => '/home/spaceuser']);
    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Space Site', 'slug' => 'space-site', 'domain' => 'space.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
    ]);
    $this->bucket = StorageDestination::create([
        'name' => 'Bucket', 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);
    $this->target = BackupTarget::create([
        'application_id' => $this->application->id, 'storage_destination_id' => $this->bucket->id,
        'type' => 'full', 'retention_count' => 3, 'enabled' => true, 'frequency' => 'daily',
    ]);
    $this->backup = Backup::create([
        'backup_target_id' => $this->target->id, 'application_id' => $this->application->id, 'type' => 'full',
        'status' => 'verified', 'size_bytes' => 4096, 'manifest' => ['key' => 'x.tar.gz'], 'verified_at' => now(),
    ]);
});

/** `$usable` bytes free above the reserve, on every disk. */
function usableSpace(int $usable): void
{
    $folder = Mockery::mock(LocalBackupFolder::class, [app(ServerOps::class), app(HomeDirectoryAccess::class)])->makePartial();
    $folder->shouldReceive('space')->andReturn(['free' => 1024 ** 3, 'total' => 100 * 1024 ** 3]);
    app()->instance(LocalBackupFolder::class, $folder);
    config(['server.backups.local.min_free_percent' => 0, 'server.backups.local.min_free_bytes' => 1024 ** 3 - $usable]);
}

describe('restore', function () {
    // Download 4096 (from a bucket) + unpacked, at least the archive, 4096 +
    // the safety backup, estimated from the last one, 4096.
    it('is refused before anything is queued when it would not fit, with the numbers', function () {
        usableSpace(12287);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/backups/{$this->backup->id}/restore", ['confirm' => 'space.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('disk_space');

        expect($response->json('errors.disk_space.0'))->toBe(__('errors/server.not_enough_disk_space', [
            'needed' => Number::fileSize(12288, 1), 'free' => Number::fileSize(12287, 1),
        ]))
            ->and(Restore::count())->toBe(0);
        Queue::assertNotPushed(RunRestore::class);
    });

    it('starts when it fits', function () {
        usableSpace(12288);

        $this->actingAs($this->admin)->postJson("/api/backups/{$this->backup->id}/restore", ['confirm' => 'space.test'])->assertStatus(202);

        Queue::assertPushed(RunRestore::class);
    });

    it('needs no room for a safety copy the user turned off', function () {
        usableSpace(8192);

        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$this->backup->id}/restore", ['confirm' => 'space.test', 'safety_copy' => false])
            ->assertStatus(202);
    });

    it('counts the measured size of the site, not only the archive', function () {
        $this->application->forceFill(['directory_size_bytes' => 100000])->save();
        usableSpace(50000);

        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$this->backup->id}/restore", ['confirm' => 'space.test', 'type' => 'filesystem', 'safety_copy' => false])
            ->assertStatus(422);
    });

    it('refuses a database restore the same way', function () {
        $database = Database::create(['name' => 'spacedb', 'engine' => 'mysql', 'size_bytes' => 100000]);
        $target = BackupTarget::create(['database_id' => $database->id, 'storage_destination_id' => $this->bucket->id, 'type' => 'database', 'retention_count' => 3, 'enabled' => true, 'frequency' => 'daily']);
        $backup = Backup::create(['backup_target_id' => $target->id, 'database_id' => $database->id, 'type' => 'database', 'subject_engine' => 'mysql', 'status' => 'verified', 'size_bytes' => 4096, 'manifest' => ['key' => 'd.sql.gz'], 'verified_at' => now()]);
        usableSpace(50000);

        $this->actingAs($this->admin)
            ->postJson("/api/databases/{$database->id}/backups/{$backup->id}/restore", ['confirm' => 'spacedb', 'safety_copy' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('disk_space');

        Queue::assertNotPushed(RunRestore::class);
    });
});

describe('back up now', function () {
    it('is refused before anything is queued when the backup would not fit', function () {
        usableSpace(4095);

        $this->actingAs($this->admin)->postJson("/api/applications/{$this->application->id}/backups")
            ->assertStatus(422)
            ->assertJsonValidationErrors('disk_space');

        Queue::assertNotPushed(RunBackup::class);
    });

    it('starts when it fits', function () {
        usableSpace(4096);

        $this->actingAs($this->admin)->postJson("/api/applications/{$this->application->id}/backups")->assertStatus(202);

        Queue::assertPushed(RunBackup::class);
    });

    it('refuses a retry the same way', function () {
        $failed = Backup::create([
            'backup_target_id' => $this->target->id, 'application_id' => $this->application->id, 'type' => 'full',
            'status' => 'failed', 'manifest' => [], 'finished_at' => now(),
        ]);
        usableSpace(4095);

        $this->actingAs($this->admin)->postJson("/api/backups/{$failed->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('disk_space');

        Queue::assertNotPushed(RunBackup::class);
    });

    it('refuses a database backup the same way', function () {
        $database = Database::create(['name' => 'spacedb2', 'engine' => 'mysql', 'size_bytes' => 100000]);
        BackupTarget::create(['database_id' => $database->id, 'storage_destination_id' => $this->bucket->id, 'type' => 'database', 'retention_count' => 3, 'enabled' => true, 'frequency' => 'daily']);
        usableSpace(50000);

        $this->actingAs($this->admin)->postJson("/api/databases/{$database->id}/backups")
            ->assertStatus(422)
            ->assertJsonValidationErrors('disk_space');

        Queue::assertNotPushed(RunBackup::class);
    });
});

describe('the check itself', function () {
    it('lets through what it cannot know', function () {
        $folder = Mockery::mock(LocalBackupFolder::class, [app(ServerOps::class), app(HomeDirectoryAccess::class)])->makePartial();
        $folder->shouldReceive('space')->andReturn(['free' => null, 'total' => null]);
        app()->instance(LocalBackupFolder::class, $folder);

        // An unmeasurable disk, and an unknown size.
        app(DiskSpaceCheck::class)->require(PHP_INT_MAX >> 2, '/tmp', 'x');
        usableSpace(0);
        app(DiskSpaceCheck::class)->require(0, '/tmp', 'x');

        expect(true)->toBeTrue();
    });

    it('measures a folder not made yet on the disk it will be made on', function () {
        usableSpace(10);

        expect(fn () => app(DiskSpaceCheck::class)->require(11, '/tmp/does/not/exist/yet', 'disk_space'))
            ->toThrow(ValidationException::class);
    });
});
