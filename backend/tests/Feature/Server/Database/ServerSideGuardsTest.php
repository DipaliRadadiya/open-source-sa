<?php

use App\Enums\ExportStatus;
use App\Models\ActivityLog;
use App\Models\Database;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExport;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * FS-C14: guards that existed only in the frontend. The API took each of these
 * from anything that called it directly — another client, a second tab, a
 * script — and the screen hiding a button was the only thing in the way.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
    Cache::put('server.public_ip.resolved', '198.51.100.20', 60);

    DatabaseConnection::create([
        'engine' => 'mariadb', 'connection_type' => 'tcp', 'host' => '127.0.0.1',
        'port' => 3306, 'username' => 'panel_abc123', 'password' => 'x',
    ]);
});

function dbGuardHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function fakeDbGuardMariaDb(): void
{
    Process::fake(function ($process) {
        $sql = (string) ($process->input ?? '');

        return match (true) {
            str_contains($sql, 'information_schema.PROCESSLIST') => Process::result(
                output: "41\tpanel_abc123\tlocalhost\tNULL\tQuery\t2\texecuting\tSELECT 1\n"
                    ."42\tshop_user\tlocalhost\tshop\tSleep\t30\t\tNULL\n",
            ),
            str_contains($sql, '@@bind_address') => Process::result(output: "127.0.0.1\n"),
            str_contains($sql, 'VERSION()') => Process::result(output: '11.4.2-MariaDB'),
            default => Process::result(output: '1'),
        };
    });
}

it('refuses to delete or rename the panel\'s own database account (a)', function () {
    fakeDbGuardMariaDb();
    $database = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    $user = DatabaseUser::create([
        'database_id' => $database->id, 'username' => 'panel_abc123', 'password' => 'x',
        'connection_preference' => 'localhost', 'host' => 'localhost',
    ]);

    $this->withHeaders(dbGuardHeaders())
        ->deleteJson("/api/databases/{$database->id}/users/{$user->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');

    $this->withHeaders(dbGuardHeaders())
        ->patchJson("/api/databases/{$database->id}/users/{$user->id}", ['username' => 'renamed'])
        ->assertUnprocessable();

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->username)->toBe('panel_abc123');
    Process::assertNotRan(fn ($p) => str_contains((string) $p->input, 'DROP USER') || str_contains((string) $p->input, 'RENAME USER'));
});

it('marks the panel\'s own connection and refuses to kill it (b)', function () {
    fakeDbGuardMariaDb();

    $this->withHeaders(dbGuardHeaders())->getJson('/api/databases/processes?engine=mariadb')
        ->assertOk()
        ->assertJsonPath('processes.0.is_panel', true)
        ->assertJsonPath('processes.1.is_panel', false);

    $this->withHeaders(dbGuardHeaders())->deleteJson('/api/databases/processes/41?engine=mariadb')
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'panel_process');
    Process::assertNotRan(fn ($p) => str_contains((string) $p->input, 'KILL 41'));

    // Anyone else's is still the user's to stop.
    $this->withHeaders(dbGuardHeaders())->deleteJson('/api/databases/processes/42?engine=mariadb')
        ->assertNoContent();
    Process::assertRan(fn ($p) => str_contains((string) $p->input, 'KILL 42'));
});

it('refuses to delete an export that is still running, and clears a finished one (c)', function () {
    $database = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    $running = DatabaseExport::create([
        'database_id' => $database->id, 'database_name' => 'shop', 'engine' => 'mariadb',
        'status' => ExportStatus::Running, 'started_at' => now(),
    ]);
    $failed = DatabaseExport::create([
        'database_id' => $database->id, 'database_name' => 'shop', 'engine' => 'mariadb',
        'status' => ExportStatus::Failed,
    ]);

    $this->withHeaders(dbGuardHeaders())->deleteJson("/api/databases/exports/{$running->id}")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'export_in_progress');
    $this->withHeaders(dbGuardHeaders())->deleteJson("/api/databases/exports/{$failed->id}")
        ->assertSuccessful();

    expect($running->fresh())->not->toBeNull()
        ->and($failed->fresh())->toBeNull();
});

it('answers 409 to a second install of an engine already installing (d)', function () {
    Queue::fake();
    app(InstallTracker::class)->start('database', 'mariadb', initialStep: 'queued');

    $this->withHeaders(dbGuardHeaders())->postJson('/api/databases/engines/mariadb')
        ->assertStatus(409)
        ->assertJsonPath('reason', 'install_in_progress');

    Queue::assertNothingPushed();
});

it('asks for the restart before it makes the database, not after (e)', function () {
    fakeDbGuardMariaDb();

    $this->withHeaders(dbGuardHeaders())->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mariadb',
        'create_user' => [
            'username' => 'shop_user', 'password' => 'S3cretPass99',
            'connection_preference' => 'remote', 'host' => '203.0.113.4',
        ],
    ])->assertStatus(409)->assertJsonPath('code', 'restart_required');

    // Nothing made, nothing dropped, nothing logged for a database that is not there.
    expect(Database::count())->toBe(0)
        ->and(ActivityLog::where('action', 'created')->where('type', 'database')->count())->toBe(0);
    Process::assertNotRan(fn ($p) => str_contains((string) $p->input, 'CREATE DATABASE'));
});
