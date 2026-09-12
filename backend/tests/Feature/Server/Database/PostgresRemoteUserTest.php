<?php

use App\Models\Database;
use App\Models\User;
use App\Services\Server\Databases\PgHbaFile;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Remote database users on PostgreSQL.
 *
 * A MySQL account carries its own host, so `CREATE USER 'a'@'10.0.0.5'` is the
 * whole grant. A PostgreSQL role is cluster-wide, so the same request is three
 * facts that must agree: a `pg_hba.conf` record, `listen_addresses` bound off
 * the loopback, and an open port. These tests are about the first two.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);

    // A holder object, not properties on the test case: Pest's `test()` proxy
    // returns values, so `test()->state->written[] = ...` is an indirect modification
    // that silently has no effect. Reading the holder and writing its property
    // is a direct write and behaves.
    $this->state = new stdClass;
    $this->state->hbaPath = '/etc/postgresql/16/main/pg_hba.conf';
    $this->state->hba = "local all postgres peer\nhost all all 127.0.0.1/32 scram-sha-256\n";
    $this->state->listen = 'localhost';
    $this->state->written = [];
});

/**
 * A PostgreSQL cluster that answers the handful of things this feature asks it.
 *
 * `$this->hba` is the file on disk: a `tee` updates it, so the fake behaves
 * like a filesystem rather than replaying a fixed script — which is what lets a
 * test assert what a *second* write did.
 */
function fakeCluster(): void
{
    Process::fake(function ($process) {
        $command = (array) $process->command;
        $bin = $command[0] ?? '';
        $sql = (string) ($process->input ?? '');

        if ($bin === 'cat') {
            return Process::result(output: test()->state->hba);
        }

        if ($bin === 'tee') {
            test()->state->written[] = $sql;
            test()->state->hba = $sql;

            return Process::result(exitCode: 0);
        }

        if ($bin === 'pg_lsclusters') {
            return Process::result(output: "16 main 5432 online postgres /var/lib/postgresql/16/main /var/log/postgresql/postgresql-16-main.log\n");
        }

        if ($bin === 'systemctl') {
            return Process::result(exitCode: 0);
        }

        if ($bin === 'psql') {
            return match (true) {
                str_contains($sql, 'SHOW hba_file') => Process::result(output: test()->state->hbaPath),
                str_contains($sql, 'SHOW listen_addresses') => Process::result(output: test()->state->listen),
                str_contains($sql, 'pg_hba_file_rules') => Process::result(output: '0'),
                str_contains($sql, 'SHOW server_version') => Process::result(output: '16.4'),
                default => Process::result(output: '1'),
            };
        }

        return Process::result(exitCode: 0);
    });
}

function pgHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function pgDatabase(): Database
{
    return Database::create(['name' => 'shop', 'engine' => 'postgresql']);
}

it('refuses with 409 rather than restarting the cluster unasked', function () {
    fakeCluster();
    $database = pgDatabase();

    test()->withHeaders(pgHeaders())
        ->postJson("/api/databases/{$database->id}/users", [
            'username' => 'shop_user',
            'password' => 'S3cretPass99',
            'connection_preference' => 'remote',
            'host' => '203.0.113.4',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'restart_required');

    // Nothing half-made: no role, no rule, no restart.
    expect($database->users()->count())->toBe(0)
        ->and(test()->state->written)->toBe([]);

    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'systemctl');
});

it('creates the role, the rule and the binding once the restart is agreed', function () {
    fakeCluster();
    $database = pgDatabase();

    test()->withHeaders(pgHeaders())
        ->postJson("/api/databases/{$database->id}/users", [
            'username' => 'shop_user',
            'password' => 'S3cretPass99',
            'connection_preference' => 'remote',
            'host' => '203.0.113.4',
            'restart_cluster' => true,
        ])
        ->assertStatus(201);

    expect(test()->state->hba)->toContain('host "shop" "shop_user" 203.0.113.4/32 scram-sha-256')
        // The cluster's own rules are still there.
        ->and(test()->state->hba)->toContain('host all all 127.0.0.1/32 scram-sha-256');

    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), "ALTER SYSTEM SET listen_addresses = '*'"));
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'restart', 'postgresql@16-main']);
    // Reloaded, so the rule is live and not merely written.
    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'pg_reload_conf'));
});

it('does not restart a cluster that already listens remotely', function () {
    fakeCluster();
    $this->state->listen = '*';
    $database = pgDatabase();

    test()->withHeaders(pgHeaders())
        ->postJson("/api/databases/{$database->id}/users", [
            'username' => 'shop_user', 'password' => 'S3cretPass99',
            'connection_preference' => 'remote', 'host' => '203.0.113.4',
        ])
        ->assertStatus(201);

    // No consent was sent and none was needed — an outage bought for no change
    // is still an outage.
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'systemctl');
    expect(test()->state->hba)->toContain('203.0.113.4/32');
});

it('writes both address families for anywhere', function () {
    fakeCluster();
    $this->state->listen = '*';
    $database = pgDatabase();

    test()->withHeaders(pgHeaders())
        ->postJson("/api/databases/{$database->id}/users", [
            'username' => 'shop_user', 'password' => 'S3cretPass99',
            'connection_preference' => 'anywhere',
        ])
        ->assertStatus(201);

    expect(test()->state->hba)->toContain('0.0.0.0/0')
        ->and(test()->state->hba)->toContain('::0/0');
});

it('restores the file and never reloads when the result would not parse', function () {
    // The guard that makes editing somebody's authentication file defensible:
    // `pg_hba_file_rules` reports on the file as it is on disk, so a broken
    // result is caught before anything is signalled.
    $original = null;
    Process::fake(function ($process) use (&$original) {
        $command = (array) $process->command;
        $bin = $command[0] ?? '';
        $sql = (string) ($process->input ?? '');

        if ($bin === 'cat') {
            return Process::result(output: test()->state->hba);
        }
        if ($bin === 'tee') {
            $original ??= test()->state->hba;
            test()->state->written[] = $sql;
            test()->state->hba = $sql;

            return Process::result(exitCode: 0);
        }
        if ($bin === 'psql') {
            return match (true) {
                str_contains($sql, 'SHOW hba_file') => Process::result(output: test()->state->hbaPath),
                str_contains($sql, 'SHOW listen_addresses') => Process::result(output: '*'),
                // One line the server cannot parse.
                str_contains($sql, 'pg_hba_file_rules') => Process::result(output: '1'),
                default => Process::result(output: '1'),
            };
        }

        return Process::result(exitCode: 0);
    });

    $database = pgDatabase();

    test()->withHeaders(pgHeaders())
        ->postJson("/api/databases/{$database->id}/users", [
            'username' => 'shop_user', 'password' => 'S3cretPass99',
            'connection_preference' => 'remote', 'host' => '203.0.113.4',
        ])
        ->assertStatus(500);

    // Written, found wanting, put back.
    expect(test()->state->hba)->toBe($original)
        ->and(test()->state->hba)->not->toContain('203.0.113.4');

    Process::assertNotRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'pg_reload_conf'));
});

it('takes the rule away with the user', function () {
    fakeCluster();
    $this->state->listen = '*';
    $database = pgDatabase();
    $user = $database->users()->create([
        'username' => 'shop_user', 'password' => 'p',
        'connection_preference' => 'remote', 'host' => '203.0.113.4',
    ]);
    $this->state->hba = PgHbaFile::render($this->state->hba, [
        PgHbaFile::key('shop', 'shop_user') => PgHbaFile::lines('shop', 'shop_user', '203.0.113.4'),
    ]);

    test()->withHeaders(pgHeaders())
        ->deleteJson("/api/databases/{$database->id}/users/{$user->id}")
        ->assertStatus(204);

    // A host line naming a dropped role is a grant nobody can see.
    expect(test()->state->hba)->not->toContain('203.0.113.4')
        ->and(test()->state->hba)->not->toContain('shop_user');
});

it('writes no rule at all for a localhost user', function () {
    fakeCluster();
    $database = pgDatabase();

    test()->withHeaders(pgHeaders())
        ->postJson("/api/databases/{$database->id}/users", [
            'username' => 'shop_user', 'password' => 'S3cretPass99',
            'connection_preference' => 'localhost',
        ])
        ->assertStatus(201);

    // The cluster's own loopback rule already covers this, and adding a second
    // one would be the panel taking ownership of access it did not grant.
    expect(test()->state->hba)->not->toContain(PgHbaFile::BEGIN);
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'systemctl');
});

it('now offers remote users on the engine catalogue', function () {
    fakeCluster();

    $engines = test()->withHeaders(pgHeaders())->getJson('/api/databases/engines')->json('engines');

    // The frontend branches on this, never on the engine name.
    expect(collect($engines)->firstWhere('engine', 'postgresql')['supports_remote_users'])->toBeTrue();
});
