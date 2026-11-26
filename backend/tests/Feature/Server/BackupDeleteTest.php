<?php

use App\Enums\BackupStatus;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting a backup.
 *
 * The archive matters more than the row: deleting the row first would, on failure, leave an
 * object in the bucket that nothing in the panel knows about. So the order is archive, then
 * row, the same as retention.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

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

    $this->disk = Storage::fake('destination');

    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        builder: fn (array $config) => $this->disk,
    ));
});

function deleteHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function makeBackup(array $overrides = []): Backup
{
    $key = $overrides['key'] ?? 'shop/backup-'.uniqid().'.tar.gz';
    unset($overrides['key']);

    test()->disk->put($key, 'archive-bytes');

    return Backup::create(array_merge([
        'backup_target_id' => test()->backupTarget->id,
        'application_id' => test()->application->id,
        'type' => 'full',
        'status' => BackupStatus::Verified->value,
        'is_safety' => false,
        'manifest' => ['key' => $key],
    ], $overrides));
}

it('removes the archive as well as the record', function () {
    $backup = makeBackup(['key' => 'shop/one.tar.gz']);

    $this->withHeaders(deleteHeaders())
        ->deleteJson("/api/backups/{$backup->id}")
        ->assertNoContent();

    // Both, and in that order: a row deleted without its archive orphans the object.
    $this->disk->assertMissing('shop/one.tar.gz');
    expect(Backup::find($backup->id))->toBeNull();
});

it('keeps the record when the archive cannot be removed', function () {
    $backup = makeBackup(['key' => 'shop/two.tar.gz']);

    // A destination that refuses: keep the row, or the object is stranded for good.
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        builder: fn (array $config) => new class
        {
            public function exists(string $key): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                throw new RuntimeException('bucket unreachable');
            }
        },
    ));

    $this->withHeaders(deleteHeaders())
        ->deleteJson("/api/backups/{$backup->id}")
        ->assertStatus(422);

    expect(Backup::find($backup->id))->not->toBeNull();
});

it('deletes cleanly when the archive is already gone', function () {
    $backup = makeBackup(['key' => 'shop/three.tar.gz']);
    $this->disk->delete('shop/three.tar.gz');

    // Someone emptied the bucket by hand: the row must still be removable.
    $this->withHeaders(deleteHeaders())
        ->deleteJson("/api/backups/{$backup->id}")
        ->assertNoContent();

    expect(Backup::find($backup->id))->toBeNull();
});

it('refuses to delete a backup that is still running', function () {
    $backup = makeBackup(['status' => BackupStatus::Running->value, 'key' => 'shop/four.tar.gz']);

    // The uploader is writing to this key; deleting the row would orphan the archive.
    $this->withHeaders(deleteHeaders())
        ->deleteJson("/api/backups/{$backup->id}")
        ->assertStatus(422);

    expect(Backup::find($backup->id))->not->toBeNull();
    $this->disk->assertExists('shop/four.tar.gz');
});

it('records the deletion, including when it was a safety copy', function () {
    $backup = makeBackup(['is_safety' => true, 'key' => 'shop/safety.tar.gz']);

    $this->withHeaders(deleteHeaders())
        ->deleteJson("/api/backups/{$backup->id}")
        ->assertNoContent();

    // A safety backup is the way back from a bad restore. Deleting one is allowed, but it must
    // show up in the activity log.
    $entry = ActivityLog::query()->where('type', 'backup')->where('action', 'deleted')->firstOrFail();

    expect($entry->properties['is_safety'])->toBeTrue()
        // The sentence must name the site, not show the raw `:name`.
        ->and(__('activity.backup.deleted', $entry->properties))->not->toContain(':name')
        ->and(__('activity.backup.deleted', $entry->properties))->toContain($backup->application->name);
});

it('needs manage, not just read', function () {
    $backup = makeBackup();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'backup');

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->deleteJson("/api/backups/{$backup->id}")
        ->assertForbidden();

    expect(Backup::find($backup->id))->not->toBeNull();
});

describe('turning backups off for an application', function () {
    it('refuses while backups still exist, saying how many', function () {
        makeBackup();
        makeBackup();

        $response = $this->withHeaders(deleteHeaders())
            ->deleteJson("/api/applications/{$this->application->id}/backup-target")
            ->assertStatus(422);

        // Name the count instead of cascading quietly: archives may be the only copy.
        expect($response->json('message'))->toContain('2');
        expect(BackupTarget::find($this->backupTarget->id))->not->toBeNull();
    });

    it('deletes the target and its backups when that is confirmed', function () {
        $one = makeBackup(['key' => 'shop/a.tar.gz']);
        $two = makeBackup(['key' => 'shop/b.tar.gz']);

        $this->withHeaders(deleteHeaders())
            ->deleteJson("/api/applications/{$this->application->id}/backup-target", ['delete_backups' => true])
            ->assertNoContent();

        $this->disk->assertMissing('shop/a.tar.gz');
        $this->disk->assertMissing('shop/b.tar.gz');

        expect(Backup::find($one->id))->toBeNull()
            ->and(Backup::find($two->id))->toBeNull()
            ->and(BackupTarget::find($this->backupTarget->id))->toBeNull();
    });

    it('refuses while a backup is running', function () {
        makeBackup(['status' => BackupStatus::Running->value]);

        $this->withHeaders(deleteHeaders())
            ->deleteJson("/api/applications/{$this->application->id}/backup-target", ['delete_backups' => true])
            ->assertStatus(422);

        expect(BackupTarget::find($this->backupTarget->id))->not->toBeNull();
    });

    it('deletes a target that never produced anything', function () {
        $this->withHeaders(deleteHeaders())
            ->deleteJson("/api/applications/{$this->application->id}/backup-target")
            ->assertNoContent();

        expect(BackupTarget::find($this->backupTarget->id))->toBeNull();
    });
});

describe('retention', function () {
    it('applies immediately when it is lowered, not at the next run', function () {
        $backups = collect(range(1, 5))->map(fn (int $i) => makeBackup(['key' => "shop/r{$i}.tar.gz"]));

        $this->withHeaders(deleteHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", [
                'storage_destination_id' => $this->destination->id,
                'type' => 'full',
                'retention_count' => 2,
                'frequency' => 'daily',
                'enabled' => true,
            ])->assertOk();

        expect(Backup::where('backup_target_id', $this->backupTarget->id)->count())->toBe(2);

        // The newest two survive.
        $this->disk->assertMissing('shop/r1.tar.gz');
        $this->disk->assertExists('shop/r5.tar.gz');
    });

    it('never counts or deletes a safety backup', function () {
        makeBackup(['key' => 'shop/s1.tar.gz']);
        makeBackup(['key' => 'shop/s2.tar.gz']);
        $safety = makeBackup(['is_safety' => true, 'key' => 'shop/s3.tar.gz']);

        $this->withHeaders(deleteHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", [
                'storage_destination_id' => $this->destination->id,
                'type' => 'full',
                'retention_count' => 1,
                'frequency' => 'daily',
                'enabled' => true,
            ])->assertOk();

        // Safety backups are the way back from a bad restore; retention must never remove them.
        expect(Backup::find($safety->id))->not->toBeNull();
        $this->disk->assertExists('shop/s3.tar.gz');
    });

    it('deletes nothing when retention is raised', function () {
        collect(range(1, 3))->each(fn (int $i) => makeBackup(['key' => "shop/u{$i}.tar.gz"]));

        $this->withHeaders(deleteHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", [
                'storage_destination_id' => $this->destination->id,
                'type' => 'full',
                'retention_count' => 30,
                'frequency' => 'daily',
                'enabled' => true,
            ])->assertOk();

        expect(Backup::where('backup_target_id', $this->backupTarget->id)->count())->toBe(3);
    });

    it('leaves unverified backups alone', function () {
        makeBackup(['key' => 'shop/v1.tar.gz']);
        $failed = makeBackup(['status' => BackupStatus::Failed->value, 'key' => 'shop/v2.tar.gz']);

        $this->withHeaders(deleteHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", [
                'storage_destination_id' => $this->destination->id,
                'type' => 'full',
                'retention_count' => 1,
                'frequency' => 'daily',
                'enabled' => true,
            ])->assertOk();

        // A failed run is not counted as a copy, but it is not surplus either: deleting it is the
        // user's call.
        expect(Backup::find($failed->id))->not->toBeNull();
    });
});
