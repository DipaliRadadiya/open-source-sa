<?php

use App\Models\ActivityLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Closing remote access again once no remote user needs it.
 *
 * Opening it restarts the engine, so closing does too: it only happens when asked, only when the
 * panel opened it (its own drop-in or ALTER SYSTEM), and never while a remote user is left.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);

    $this->rc = new stdClass;
    $this->rc->bind = '0.0.0.0';
    $this->rc->dropIn = "# Managed by the panel: lets remote database users connect.\n[mysqld]\nbind-address = 0.0.0.0\n";
    $this->rc->restartFails = false;
    $this->rc->listen = '*';
    $this->rc->sourcefile = '/var/lib/postgresql/16/main/postgresql.auto.conf';
    $this->rc->commands = [];
});

/** MariaDB: `bind_address` from SQL, the drop-in from `cat`, removal and restart recorded. */
function rcFakeMariaDb(): void
{
    Process::fake(function ($process) {
        $command = array_values(array_filter((array) $process->command, fn ($a) => ! in_array($a, ['sudo', '-n'], true)));
        $sql = (string) ($process->input ?? '');
        test()->rc->commands[] = $command;
        $bin = $command[0] ?? '';

        if ($bin === 'cat') {
            return test()->rc->dropIn !== null && $command[1] === '/etc/mysql/mariadb.conf.d/99-panel-remote.cnf'
                ? Process::result(output: test()->rc->dropIn)
                : Process::result(errorOutput: 'No such file or directory', exitCode: 1);
        }

        if ($bin === 'rm') {
            test()->rc->dropIn = null;

            return Process::result();
        }

        if ($bin === 'tee') {
            test()->rc->dropIn = $sql;

            return Process::result();
        }

        if ($bin === 'systemctl' && in_array('restart', $command, true)) {
            if (test()->rc->restartFails) {
                return Process::result(errorOutput: 'Job failed', exitCode: 1);
            }
            test()->rc->bind = test()->rc->dropIn === null ? '127.0.0.1' : '0.0.0.0';

            return Process::result();
        }

        if (str_contains($sql, '@@bind_address')) {
            return Process::result(output: test()->rc->bind."\n");
        }

        return Process::result(output: '1');
    });
}

function rcFakeCluster(): void
{
    Process::fake(function ($process) {
        $command = (array) $process->command;
        $sql = (string) ($process->input ?? '');
        test()->rc->commands[] = $command;

        return match (true) {
            ($command[0] ?? '') === 'pg_lsclusters' => Process::result(output: "16 main 5432 online postgres /var/lib/postgresql/16/main /var/log/postgresql/postgresql-16-main.log\n"),
            ($command[0] ?? '') === 'systemctl' => Process::result(),
            str_contains($sql, 'SHOW listen_addresses') => Process::result(output: test()->rc->listen),
            str_contains($sql, "name = 'listen_addresses'") => Process::result(output: test()->rc->sourcefile),
            str_contains($sql, 'RESET listen_addresses') => (function () {
                test()->rc->listen = 'localhost';
                test()->rc->sourcefile = '';

                return Process::result();
            })(),
            str_contains($sql, 'SHOW server_version') => Process::result(output: '16.4'),
            default => Process::result(output: '1'),
        };
    });
}

function rcRemoteUser(string $engine): DatabaseUser
{
    $database = Database::create(['name' => 'shop', 'engine' => $engine]);

    return DatabaseUser::create([
        'database_id' => $database->id,
        'username' => 'shop_remote',
        'password' => 'S3cretPass99',
        'connection_preference' => 'remote',
        'host' => '203.0.113.4',
    ]);
}

function rcClose(string $engine, array $body = ['restart' => true])
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->postJson("/api/databases/connections/{$engine}/remote-access/close", $body);
}

function rcRestarted(): bool
{
    return collect(test()->rc->commands)->contains(fn ($c) => in_array('restart', $c, true));
}

it('says MariaDB can be closed when the panel opened it and no remote user is left', function () {
    rcFakeMariaDb();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/databases/connections/mariadb/remote-access')
        ->assertOk()
        ->assertJsonPath('remote_access.listening_remotely', true)
        ->assertJsonPath('remote_access.opened_by_panel', true)
        ->assertJsonPath('remote_access.remote_users', 0)
        ->assertJsonPath('remote_access.can_close', true);
});

it('closes MariaDB: removes the drop-in, restarts, records it', function () {
    rcFakeMariaDb();

    rcClose('mariadb')
        ->assertOk()
        ->assertJsonPath('remote_access.listening_remotely', false)
        ->assertJsonPath('remote_access.can_close', false);

    expect($this->rc->dropIn)->toBeNull()
        ->and(rcRestarted())->toBeTrue()
        ->and(ActivityLog::where('type', 'database')->where('action', 'remote_access_closed')->exists())->toBeTrue();
});

it('refuses while a remote user is left, naming it, and restarts nothing', function () {
    rcFakeMariaDb();
    rcRemoteUser('mariadb');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/databases/connections/mariadb/remote-access')
        ->assertJsonPath('remote_access.remote_users', 1)
        ->assertJsonPath('remote_access.can_close', false);

    rcClose('mariadb')->assertUnprocessable()->assertJsonValidationErrors('engine');
    expect(rcClose('mariadb')->json('errors.engine.0'))->toContain('shop_remote');

    expect($this->rc->dropIn)->not->toBeNull()->and(rcRestarted())->toBeFalse();
});

it('leaves remote access the server itself configured alone', function () {
    rcFakeMariaDb();
    $this->rc->dropIn = null; // listening, but not through the panel's file

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/databases/connections/mariadb/remote-access')
        ->assertJsonPath('remote_access.listening_remotely', true)
        ->assertJsonPath('remote_access.opened_by_panel', false)
        ->assertJsonPath('remote_access.can_close', false);

    rcClose('mariadb')->assertUnprocessable();
    expect(rcRestarted())->toBeFalse();
});

it('refuses when already local only', function () {
    rcFakeMariaDb();
    $this->rc->bind = '127.0.0.1';

    rcClose('mariadb')->assertUnprocessable();
    expect(rcRestarted())->toBeFalse();
});

it('puts the drop-in back when the restart fails', function () {
    rcFakeMariaDb();
    $this->rc->restartFails = true;

    rcClose('mariadb')->assertStatus(500)->assertJsonStructure(['reference']);

    expect($this->rc->dropIn)->toContain('bind-address = 0.0.0.0')
        ->and(ActivityLog::where('action', 'remote_access_closed')->exists())->toBeFalse();
});

it('restarts only when the restart is agreed', function () {
    rcFakeMariaDb();

    rcClose('mariadb', [])->assertUnprocessable()->assertJsonValidationErrors('restart');
    expect(rcRestarted())->toBeFalse();
});

it('lets a view-only user see the state but not close it', function () {
    rcFakeMariaDb();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'database', view: true, manage: false);
    $token = $viewer->createToken('v')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/databases/connections/mariadb/remote-access')->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/databases/connections/mariadb/remote-access/close', ['restart' => true])->assertForbidden();

    expect(rcRestarted())->toBeFalse();
});

it('has no remote access switch for MongoDB', function () {
    rcFakeMariaDb();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/databases/connections/mongodb/remote-access')->assertNotFound();
});

it('closes PostgreSQL by resetting its ALTER SYSTEM value and restarting the cluster', function () {
    rcFakeCluster();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/databases/connections/postgresql/remote-access')
        ->assertJsonPath('remote_access.opened_by_panel', true)
        ->assertJsonPath('remote_access.can_close', true);

    rcClose('postgresql')->assertOk()->assertJsonPath('remote_access.listening_remotely', false);

    expect(collect($this->rc->commands)->contains(fn ($c) => $c === ['systemctl', 'restart', 'postgresql@16-main'])
        || collect($this->rc->commands)->contains(fn ($c) => array_slice($c, -3) === ['systemctl', 'restart', 'postgresql@16-main']))->toBeTrue();
});

it('leaves a PostgreSQL cluster opened in postgresql.conf alone', function () {
    rcFakeCluster();
    $this->rc->sourcefile = '/etc/postgresql/16/main/postgresql.conf';

    rcClose('postgresql')->assertUnprocessable();
    expect(rcRestarted())->toBeFalse();
});
