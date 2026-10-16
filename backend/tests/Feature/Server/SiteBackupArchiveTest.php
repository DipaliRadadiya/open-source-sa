<?php

use App\Actions\Server\Application\DeleteApplication;
use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Deleting a site archives its backups (2026-10-16, the follow-up to database
 * backups). Before, the rows went with the site and the archives stayed in the
 * bucket with nothing in the panel pointing at them — unlisted, unbillable to
 * anyone's attention, and unrecoverable from the panel.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    Process::fake();

    $this->fakeDisk = Storage::fake('destination');
    $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(app(StorageDriverFactory::class), fn (array $config) => $this->fakeDisk));

    $this->site = Application::forceCreate([
        'system_user_id' => SystemUser::create(['username' => 'arch', 'home_path' => '/home/arch'])->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com', 'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
    ]);
    $destination = StorageDestination::create(['name' => 'B', 'provider' => 's3', 'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's']]);
    $this->siteTarget = BackupTarget::create(['application_id' => $this->site->id, 'storage_destination_id' => $destination->id, 'type' => 'full', 'retention_count' => 3, 'enabled' => true, 'frequency' => 'daily']);

    $this->fakeDisk->put('shop.example.com/a.tar.gz', str_repeat('x', 10));
    $this->backup = Backup::create(['backup_target_id' => $this->siteTarget->id, 'application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Verified, 'size_bytes' => 10, 'manifest' => ['key' => 'shop.example.com/a.tar.gz']]);
});

function archiveHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

it('keeps the site\'s backups, named by its domain, when the site is deleted', function () {
    app(DeleteApplication::class)->execute($this->site);

    $backup = $this->backup->fresh();

    expect($backup)->not->toBeNull()
        ->and($backup->application_id)->toBeNull()
        ->and($backup->backup_target_id)->toBeNull()
        ->and($backup->subject_name)->toBe('shop.example.com')
        ->and($backup->isArchived())->toBeTrue()
        ->and($backup->isDatabaseBackup())->toBeFalse()
        ->and(BackupTarget::find($this->siteTarget->id))->toBeNull();

    $this->fakeDisk->assertExists('shop.example.com/a.tar.gz');

    $this->withHeaders(archiveHeaders())->getJson('/api/backups?filter[archived]=1')
        ->assertOk()->assertJsonCount(1, 'backups')
        ->assertJsonPath('backups.0.archived', true)
        ->assertJsonPath('backups.0.site_name', 'shop.example.com')
        ->assertJsonPath('backups.0.database_name', null);
});

it('lets an archived site backup be deleted, archive and all, but not restored', function () {
    app(DeleteApplication::class)->execute($this->site);

    $this->withHeaders(archiveHeaders())->postJson("/api/backups/{$this->backup->id}/restore", ['confirm' => 'shop.example.com'])
        ->assertStatus(422)->assertJsonFragment(['backup' => [__('backup.errors.restore_archived_site')]]);

    $this->withHeaders(archiveHeaders())->deleteJson("/api/backups/{$this->backup->id}")->assertNoContent();

    expect(Backup::find($this->backup->id))->toBeNull();
    $this->fakeDisk->assertMissing('shop.example.com/a.tar.gz');
});

it('refuses to delete a site while one of its backups is running', function () {
    Backup::create(['backup_target_id' => $this->siteTarget->id, 'application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Running]);

    $this->withHeaders(archiveHeaders())->deleteJson("/api/applications/{$this->site->id}")
        ->assertStatus(422)->assertJsonFragment(['application' => [__('backup.errors.delete_site_backup_running')]]);

    expect(Application::find($this->site->id))->not->toBeNull();
});

it('is not restorable into a database either', function () {
    app(DeleteApplication::class)->execute($this->site);
    $db = Database::create(['name' => 'x', 'engine' => 'mysql']);
    BackupTarget::create(['database_id' => $db->id, 'storage_destination_id' => $this->siteTarget->storage_destination_id, 'type' => 'database', 'retention_count' => 1, 'enabled' => true, 'frequency' => 'daily']);

    $this->withHeaders(archiveHeaders())->postJson("/api/databases/{$db->id}/backups/{$this->backup->id}/restore", ['confirm' => 'x'])
        ->assertStatus(422)->assertJsonFragment(['backup' => [__('backup.errors.restore_wrong_database')]]);
});
