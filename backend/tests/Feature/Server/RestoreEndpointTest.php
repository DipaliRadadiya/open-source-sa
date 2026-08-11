<?php

use App\Jobs\RunRestore;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * The guardrails. Every one of these is a way someone could destroy a site by
 * accident, and the endpoint exists as much to refuse as to accept.
 */

beforeEach(function () {
    Queue::fake();

    // The Administrator role is defined as "every permission", so without a
    // catalog it grants nothing and every route below answers 403.
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create([
        'username' => 'endpointuser',
        'home_path' => '/home/endpointuser',
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Endpoint Site',
        'slug' => 'endpoint-site',
        'domain' => 'endpoint.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $destination = StorageDestination::create([
        'name' => 'Backups', 'endpoint' => '', 'region' => 'us-east-1',
        'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret',
    ]);

    $this->backupTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'full',
        'retention_count' => 3,
        'enabled' => true,
        'frequency' => 'daily',
    ]);
});

function endpointBackup(array $overrides = []): Backup
{
    return Backup::create(array_merge([
        'backup_target_id' => test()->backupTarget->id,
        'application_id' => test()->application->id,
        'type' => 'full',
        'status' => 'verified',
        'size_bytes' => 2048,
        'manifest' => ['key' => 'backups/endpoint.test/2026-08-04/1.tar.gz'],
        'verified_at' => now(),
    ], $overrides));
}

it('starts a restore when the domain is typed correctly', function () {
    $backup = endpointBackup();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
        ->assertStatus(202);

    expect($response->json('restore.status'))->toBe('pending')
        ->and($response->json('restore.total_steps'))->toBe(7);

    Queue::assertPushed(RunRestore::class);
});

it('refuses when the typed confirmation does not match the domain', function () {
    $backup = endpointBackup();

    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.tes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('confirm');

    // Nothing queued: a near-miss is still a miss.
    Queue::assertNothingPushed();
    expect(Restore::count())->toBe(0);
});

it('refuses without a confirmation at all', function () {
    $backup = endpointBackup();

    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore")
        ->assertStatus(422)
        ->assertJsonValidationErrors('confirm');
});

it('refuses to restore a backup that was never verified', function () {
    // We could not prove this artefact arrived intact. Overwriting a working
    // site with it would be trading something known-good for something we
    // already know we cannot vouch for.
    $backup = endpointBackup(['status' => 'failed', 'verified_at' => null]);

    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('backup');

    Queue::assertNothingPushed();
});

it('refuses a second restore while one is running', function () {
    $backup = endpointBackup();

    Restore::create([
        'backup_id' => $backup->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'status' => 'running',
    ]);

    // Two restores writing the same site directory at once is the one thing
    // worse than none.
    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('backup');
});

it('refuses to restore a database from a files-only backup', function () {
    $backup = endpointBackup(['type' => 'filesystem']);

    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", [
            'confirm' => 'endpoint.test',
            'type' => 'full',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('always aims the restore at the backup\'s own application', function () {
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Someone Else',
        'slug' => 'someone-else',
        'domain' => 'other.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $backup = endpointBackup();

    // The request cannot redirect a restore at another site — that would write
    // one customer's database over another's.
    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", [
            'confirm' => 'endpoint.test',
            'application_id' => $other->id,
        ])
        ->assertStatus(202);

    expect(Restore::first()->application_id)->toBe($this->application->id);
});

describe('permissions', function () {
    it('denies a user who can configure backups but not manage them', function () {
        $user = User::factory()->create();
        grantPermission($user, 'backup', view: true, manage: false);

        // Setting a schedule and replacing a live site with last Tuesday are
        // different decisions.
        $this->actingAs($user)
            ->postJson('/api/backups/'.endpointBackup()->id.'/restore', ['confirm' => 'endpoint.test'])
            ->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        $this->postJson('/api/backups/'.endpointBackup()->id.'/restore', ['confirm' => 'endpoint.test'])
            ->assertUnauthorized();
    });

    it('lets a viewer read the history but not start one', function () {
        $user = User::factory()->create();
        grantPermission($user, 'backup', view: true, manage: false);

        $this->actingAs($user)->getJson('/api/restores')->assertOk();
    });
});
