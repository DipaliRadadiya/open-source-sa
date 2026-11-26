<?php

use App\Actions\Server\Backup\DeleteBackup;
use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\Steps\PruneOldBackups;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * A backup is an address: a key *and* a destination, recorded on the row itself.
 *
 * Reaching the destination through the (editable) target would make repointing it
 * move history: downloads, restores, deletes and retention would hit the wrong bucket.
 *
 * Two fake disks are picked by the destination's own credentials, because a single
 * shared fake cannot tell a right bucket from a wrong one.
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

    $this->oldDestination = StorageDestination::create([
        'name' => 'Old Provider',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'old-bucket', 'access_key' => 'old-key', 'secret_key' => 's'],
    ]);

    $this->newDestination = StorageDestination::create([
        'name' => 'New Provider',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'new-bucket', 'access_key' => 'new-key', 'secret_key' => 's'],
    ]);

    $this->oldDisk = Storage::fake('old-bucket');
    $this->newDisk = Storage::fake('new-bucket');

    // Discriminates on the credentials DestinationDisk::config() builds, as in production.
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
        app(StorageDriverFactory::class),
        builder: fn (array $config) => $config['key'] === 'old-key' ? $this->oldDisk : $this->newDisk,
    ));

    $this->backupTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $this->oldDestination->id,
        'type' => 'full', 'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
    ]);
});

/** A verified backup written to the OLD destination, then the target repointed. */
function backupWrittenBeforeRepoint(string $key = 'backups/shop/archive.tar.gz'): Backup
{
    $backup = Backup::create([
        'backup_target_id' => test()->backupTarget->id,
        'application_id' => test()->application->id,
        'type' => 'full',
        'status' => BackupStatus::Verified,
        'manifest' => ['key' => $key],
        'size_bytes' => 100,
        'verified_at' => now(),
        'finished_at' => now(),
    ]);

    test()->oldDisk->put($key, 'archive-bytes');

    // The config change the user makes, long after the archive was written.
    test()->backupTarget->update(['storage_destination_id' => test()->newDestination->id]);

    return $backup->fresh();
}

it('records the destination it was written to when the backup is created', function () {
    // Stamped in the model's creating hook, not by the caller.
    $backup = Backup::create([
        'backup_target_id' => $this->backupTarget->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'status' => BackupStatus::Running,
    ]);

    expect($backup->storage_destination_id)->toBe($this->oldDestination->id);
});

it('downloads an old backup from where it was actually written', function () {
    $backup = backupWrittenBeforeRepoint();

    // `exists()` must be asked of the OLD bucket, not the new one, or a good archive
    // answers "download_missing".
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->getJson("/api/backups/{$backup->id}/download")
        ->assertSuccessful()
        ->assertJsonPath('download.filename', fn ($v) => is_string($v));
});

it('deletes the archive from the old destination, not the new one', function () {
    $backup = backupWrittenBeforeRepoint();

    app(DeleteBackup::class)->execute($backup, $this->admin->id);

    // Row gone while the object stays is the orphan case: billed, and invisible in the panel.
    expect($this->oldDisk->exists('backups/shop/archive.tar.gz'))->toBeFalse()
        ->and(Backup::query()->whereKey($backup->id)->exists())->toBeFalse();
});

it('names the destination the archive is on, not the one configured today', function () {
    $backup = backupWrittenBeforeRepoint();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->getJson("/api/backups?filter[application_id]={$this->application->id}")
        ->assertSuccessful()
        // History must show where a backup went, not today's target provider.
        ->assertJsonPath('backups.0.storage_destination_name', 'Old Provider');
});

it('prunes each old archive from its own destination', function () {
    // Unattended, so the worst case: retention must use each row's own disk, or after a
    // repoint it deletes the row while the object stays.
    $old = backupWrittenBeforeRepoint('backups/shop/old.tar.gz');

    // Retention of 1, so `$old` is surplus once the current run verifies.
    $this->backupTarget->update(['retention_count' => 1]);

    $current = Backup::create([
        'backup_target_id' => $this->backupTarget->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'status' => BackupStatus::Verified,
        'manifest' => ['key' => 'backups/shop/current.tar.gz'],
        'verified_at' => now(),
    ]);

    $this->newDisk->put('backups/shop/current.tar.gz', 'current-bytes');

    app(PruneOldBackups::class)->run(
        new BackupContext($current, $this->backupTarget->fresh(), '/tmp'),
    );

    // Row gone AND object gone — together. Either one alone is the bug.
    expect(Backup::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and($this->oldDisk->exists('backups/shop/old.tar.gz'))->toBeFalse()
        // The current backup, on the new destination, is untouched.
        ->and($this->newDisk->exists('backups/shop/current.tar.gz'))->toBeTrue();
});
