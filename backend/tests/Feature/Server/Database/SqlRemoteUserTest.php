<?php

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
 * Remote database users on MySQL and MariaDB.
 *
 * Ubuntu ships both with `bind-address = 127.0.0.1`. On the 26.04 test server
 * MariaDB listened on 127.0.0.1:3306 only, so a user saved as "remote" had the
 * right account and grants and could never connect. Same shape as PostgreSQL's
 * `listen_addresses` (PostgresRemoteUserTest): widen it, restart, but only
 * once the caller has agreed to the restart.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
    Cache::put('server.public_ip.resolved', '198.51.100.20', 60);

    $this->state = new stdClass;
    $this->state->bind = '127.0.0.1';
    $this->state->written = [];
});

function fakeMariaDb(): void
{
    Process::fake(function ($process) {
        $command = array_values(array_filter((array) $process->command, fn ($a) => ! in_array($a, ['sudo', '-n'], true)));
        $sql = (string) ($process->input ?? '');

        if (($command[0] ?? '') === 'tee') {
            test()->state->written[$command[1]] = $sql;

            return Process::result();
        }

        if (str_contains($sql, '@@bind_address')) {
            return Process::result(output: test()->state->bind."\n");
        }

        return Process::result(output: '1');
    });
}

function mariaDbUser(array $extra = []): TestResponse
{
    $database = Database::create(['name' => 'shop', 'engine' => 'mariadb']);

    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->postJson("/api/databases/{$database->id}/users", array_merge([
            'username' => 'shop_user',
            'password' => 'S3cretPass99',
            'connection_preference' => 'remote',
            'host' => '203.0.113.4',
        ], $extra));
}

it('asks before restarting MariaDB to listen off the box', function () {
    fakeMariaDb();

    mariaDbUser()
        ->assertStatus(409)
        ->assertJsonPath('code', 'restart_required')
        ->assertJsonPath('message', __('errors/database.remote_access_restart_required', ['engine' => 'MariaDB']));

    expect(Database::first()->users()->count())->toBe(0);
    Process::assertNotRan(fn ($p) => in_array('restart', (array) $p->command, true));
});

it('binds every interface and restarts once the restart is agreed', function () {
    fakeMariaDb();

    mariaDbUser(['restart_cluster' => true])->assertCreated();

    expect($this->state->written)->toHaveKey('/etc/mysql/mariadb.conf.d/99-panel-remote.cnf')
        ->and($this->state->written['/etc/mysql/mariadb.conf.d/99-panel-remote.cnf'])->toContain("[mysqld]\nbind-address = 0.0.0.0");

    Process::assertRan(fn ($p) => array_slice((array) $p->command, -3) === ['systemctl', 'restart', 'mariadb']);
});

it('does not restart an engine that already listens remotely', function () {
    fakeMariaDb();
    $this->state->bind = '0.0.0.0';

    mariaDbUser()->assertCreated();

    Process::assertNotRan(fn ($p) => in_array('restart', (array) $p->command, true));
});

it('leaves a local user alone whatever the engine listens on', function () {
    fakeMariaDb();

    mariaDbUser(['connection_preference' => 'localhost', 'host' => null])->assertCreated();
});

it('gives a remote user a connection string to this server, not to its own host', function () {
    fakeMariaDb();
    $this->state->bind = '0.0.0.0';

    mariaDbUser()
        ->assertCreated()
        ->assertJsonPath('user.connection_string', 'mariadb://shop_user:S3cretPass99@198.51.100.20:3306/shop');
});

/*
 * Bug #37: a shape-only regex took 999.1.1.1. The user was created, ufw
 * refused the rule with a 500, and the half-made rule could not be deleted.
 * Like v7, an address that is not remote at all is refused too.
 */
it('refuses a remote address that is not one', function (string $host, string $message) {
    fakeMariaDb();

    mariaDbUser(['host' => $host, 'restart_cluster' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['host' => __($message)]);

    Process::assertNotRan(fn ($p) => in_array('ufw', (array) $p->command, true));
    expect(DatabaseUser::count())->toBe(0);
})->with([
    'octet over 255' => ['999.1.1.1', 'errors/database.remote_host_invalid'],
    'prefix over 32' => ['10.0.0.0/33', 'errors/database.remote_host_invalid'],
    'ipv6' => ['2001:db8::1', 'errors/database.remote_host_invalid'],
    'every address' => ['0.0.0.0', 'errors/database.remote_host_not_remote'],
    'a /0' => ['10.0.0.0/0', 'errors/database.remote_host_not_remote'],
    'loopback' => ['127.0.0.1', 'errors/database.remote_host_not_remote'],
]);

it('still takes a real address or range', function (string $host) {
    fakeMariaDb();
    $this->state->bind = '0.0.0.0';

    mariaDbUser(['host' => $host])->assertCreated();
})->with(['203.0.113.4', '203.0.113.0/24', '10.1.2.3/32']);

// The API reference has always shown this request. The host is not used for
// "anywhere", so the stricter remote check must not break a caller who sends it.
it('still takes the documented 0.0.0.0/0 alongside "anywhere"', function () {
    fakeMariaDb();
    $this->state->bind = '0.0.0.0';

    mariaDbUser(['connection_preference' => 'anywhere', 'host' => '0.0.0.0/0'])->assertCreated();
});
