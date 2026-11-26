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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * The guardrails: each is a way someone could destroy a site by accident, and the endpoint
 * exists as much to refuse as to accept.
 */

beforeEach(function () {
    Queue::fake();

    // The Administrator role is "every permission", so without a catalog every route answers 403.
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
        'name' => 'Backups',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret'],
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

    // Counted from the configured pipeline: a literal goes stale whenever a step is added.
    expect($response->json('restore.status'))->toBe('pending')
        ->and($response->json('restore.total_steps'))
        ->toBe(count((array) config('server.backups.restore_steps')));

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
    // The artefact's integrity is unproven; overwriting a working site with it is not worth the risk.
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

    // Two restores writing the same site directory at once are worse than none.
    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('backup');
});

/*
 * Two restores sent together both pass the request's check and create a pending row, and the
 * queue drops the second job (one per site), leaving that row pending for ever. The other
 * request is simulated by creating its row right after this request's check has run.
 */
it('refuses a second restore that arrives while the first is being created', function () {
    $backup = endpointBackup();
    $raced = false;

    DB::listen(function ($query) use (&$raced, $backup) {
        if ($raced || ! str_contains($query->sql, 'from "restores"')) {
            return;
        }

        $raced = true;
        Restore::create(['backup_id' => $backup->id, 'application_id' => $this->application->id, 'type' => 'full', 'status' => 'pending']);
    });

    $this->actingAs($this->admin)
        ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('backup');

    expect($raced)->toBeTrue()
        ->and(Restore::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('answers a restore started while another is starting as busy, not with a second row', function () {
    $backup = endpointBackup();
    $held = Cache::lock('restore-start:application-'.$this->application->id, 30);
    $held->get();

    try {
        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup');
    } finally {
        $held->release();
    }

    expect(Restore::count())->toBe(0);
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

describe('a full backup of a site with no database (2026-10-18)', function () {
    // The dump step records `databases: []` for a site with nothing to dump; a full restore of
    // that must be refused up front, not fail at "Restoring the database".
    function noDatabaseBackup(): Backup
    {
        return endpointBackup(['manifest' => ['key' => 'backups/endpoint.test/x.tar.gz', 'databases' => []]]);
    }

    it('restores its files when no type is given', function () {
        $backup = noDatabaseBackup();

        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
            ->assertStatus(202)
            ->assertJsonPath('restore.type', 'filesystem');
    });

    it('refuses a database or full restore up front, before anything is touched', function (string $type) {
        $backup = noDatabaseBackup();

        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test', 'type' => $type])
            ->assertStatus(422)
            ->assertJsonPath('errors.type.0', __('backup.errors.restore_no_database'));

        expect(Restore::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with(['full', 'database']);

    it('says in the list that it holds no database', function () {
        noDatabaseBackup();
        endpointBackup(['manifest' => ['key' => 'k', 'databases' => [['name' => 'shop']]]]);

        $rows = $this->actingAs($this->admin)->getJson('/api/backups')->assertOk()->json('backups');

        expect(collect($rows)->pluck('has_database')->sort()->values()->all())->toBe([false, true]);
    });

    it('treats a backup from before the manifest recorded databases as having one', function () {
        // Older rows have no `databases` key: keep working rather than refuse them.
        $backup = endpointBackup();

        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
            ->assertStatus(202)
            ->assertJsonPath('restore.type', 'full');
    });
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

    // The request cannot redirect a restore at another site, which would overwrite another
    // customer's database.
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

        // Setting a schedule and replacing a live site with an old backup are different decisions.
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

describe('the safety copy switch (2026-10-29)', function () {
    it('takes a copy unless told not to', function () {
        $backup = endpointBackup();

        $this->actingAs($this->admin)
            ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test'])
            ->assertStatus(202)
            ->assertJsonPath('restore.safety_copy', true);

        expect(Restore::latest('id')->first()->safety_copy)->toBeTrue();
    });

    it('records the choice and counts one step fewer when the copy is off', function () {
        $backup = endpointBackup();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/backups/{$backup->id}/restore", ['confirm' => 'endpoint.test', 'safety_copy' => false])
            ->assertStatus(202)
            ->assertJsonPath('restore.safety_copy', false);

        expect(Restore::latest('id')->first()->safety_copy)->toBeFalse()
            ->and($response->json('restore.total_steps'))->toBe(count((array) config('server.backups.restore_steps')) - 1);
    });

    it('refuses a value that is not true or false', function () {
        $this->actingAs($this->admin)
            ->postJson('/api/backups/'.endpointBackup()->id.'/restore', ['confirm' => 'endpoint.test', 'safety_copy' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('safety_copy');
    });
});
