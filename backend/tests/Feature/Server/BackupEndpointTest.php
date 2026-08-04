<?php

use App\Enums\BackupStatus;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.example.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Offsite',
        'endpoint' => '',
        'region' => 'us-east-1',
        'bucket' => 'backups',
        'access_key' => 'k',
        'secret_key' => 's',
    ]);
});

function backupHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function targetPayload(array $overrides = []): array
{
    return array_merge([
        'storage_destination_id' => test()->destination->id,
        'type' => 'full',
        'retention_count' => 7,
        'frequency' => 'daily',
        'enabled' => true,
    ], $overrides);
}

it('returns null when backups are not configured for an application', function () {
    $this->withHeaders(backupHeaders())
        ->getJson("/api/applications/{$this->application->id}/backup-target")
        ->assertOk()
        ->assertJsonPath('backup_target', null);
});

it('configures backups for an application', function () {
    $response = $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload());

    $response->assertOk()
        ->assertJsonPath('backup_target.type', 'full')
        ->assertJsonPath('backup_target.frequency', 'daily')
        ->assertJsonPath('backup_target.retention_count', 7);

    // Localized labels come from the backend so the frontend never holds a
    // frequency or type list.
    expect($response->json('backup_target.frequency_title'))->toBe('Daily')
        ->and($response->json('backup_target.type_title'))->toBe('Files and database');
});

it('edits rather than duplicating when saved twice', function () {
    $url = "/api/applications/{$this->application->id}/backup-target";

    $this->withHeaders(backupHeaders())->putJson($url, targetPayload())->assertOk();
    $this->withHeaders(backupHeaders())->putJson($url, targetPayload(['frequency' => 'weekly']))->assertOk();

    // The table has a unique on application_id — a second row would be a
    // constraint violation, not a second schedule.
    expect(BackupTarget::count())->toBe(1)
        ->and(BackupTarget::first()->frequency)->toBe('weekly');
});

it('refuses a retention of zero', function () {
    // Zero would mean every run prunes the backup it just took, which reads
    // as "backups silently do nothing".
    $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload(['retention_count' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('retention_count');
});

it('refuses an unknown frequency', function () {
    $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload(['frequency' => 'hourly']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('frequency');
});

it('refuses a storage destination that does not exist', function () {
    $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload(['storage_destination_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('storage_destination_id');
});

describe('running a backup', function () {
    it('queues the job', function () {
        Queue::fake();

        $this->withHeaders(backupHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
            ->assertOk();

        $this->withHeaders(backupHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            // 202: the run happens on the queue, and the Backup row does not
            // exist until a worker picks it up.
            ->assertStatus(202);

        Queue::assertPushed(RunBackup::class);
    });

    it('refuses when backups are not configured', function () {
        Queue::fake();

        $this->withHeaders(backupHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    });

    it('refuses a second run while one is in flight', function () {
        Queue::fake();

        $this->withHeaders(backupHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
            ->assertOk();

        Backup::create([
            'backup_target_id' => BackupTarget::first()->id,
            'application_id' => $this->application->id,
            'type' => 'full',
            'status' => BackupStatus::Running,
        ]);

        // Two archives of the same site at once compete for the same disk.
        $this->withHeaders(backupHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    });
});

it('lists backups across every application', function () {
    $target = BackupTarget::create(array_merge(
        ['application_id' => $this->application->id],
        targetPayload(),
    ));

    Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'status' => BackupStatus::Verified,
    ]);

    $response = $this->withHeaders(backupHeaders())->getJson('/api/backups');

    $response->assertOk()
        ->assertJsonPath('backups.0.status', 'verified')
        ->assertJsonStructure(['backups', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);

    expect($response->json('backups.0.status_title'))->toBe('Complete');
});

it('denies a user without the backup permissions', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/backups')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
        ->assertForbidden();
});

it('denies an unauthenticated request', function () {
    $this->getJson('/api/backups')->assertUnauthorized();
});

it('has copy for every step, status, type and frequency in every locale', function () {
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach (['dump_database', 'archive_files', 'upload_artifact', 'verify_artifact', 'prune_old_backups'] as $step) {
            expect(__('backup.steps.'.$step))->not->toBe('backup.steps.'.$step);
            // Every step is also a failure reason, because `reason` holds the
            // step that failed.
            expect(__('backup.errors.'.$step))->not->toBe('backup.errors.'.$step);
        }

        foreach (BackupStatus::cases() as $status) {
            expect(__('backup.status.'.$status->value))->not->toBe('backup.status.'.$status->value);
        }

        foreach (BackupTarget::FREQUENCIES as $frequency) {
            expect(__('backup.frequency.'.$frequency))->not->toBe('backup.frequency.'.$frequency);
        }
    }
});
