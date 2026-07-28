<?php

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
});

/** Fake the DB clients + ufw. SQL is branched on the stdin the engine pipes. */
function fakeDb(): void
{
    Process::fake(function ($process) {
        $cmd = $process->command;
        $bin = $cmd[0] ?? '';
        $sql = (string) ($process->input ?? '');

        if (in_array($bin, ['mysql', 'mariadb'], true)) {
            return match (true) {
                str_contains($sql, 'VERSION()') => Process::result(output: '8.0.36'),
                str_contains($sql, 'SHOW DATABASES') => Process::result(output: "app_db\nother_db\ninformation_schema\nmysql\n"),
                str_contains($sql, 'SELECT COALESCE') => Process::result(output: '1048576'),
                str_contains($sql, 'SELECT 1') => Process::result(output: '1'),
                default => Process::result(exitCode: 0), // CREATE/DROP/ALTER/GRANT
            };
        }
        if ($bin === 'mongosh') {
            return Process::result(output: '1');
        }
        if ($bin === 'ufw') {
            return Process::result(output: "Status: inactive\n");
        }

        return Process::result(exitCode: 0);
    });
}

function dbAuth(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('lists engine capabilities with live version', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->getJson('/api/databases/engines')->assertOk()
        ->assertJsonPath('engines.0.engine', 'mysql')
        ->assertJsonPath('engines.0.running', true)
        ->assertJsonPath('engines.0.version', '8.0.36');
});

it('creates a database', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ])->assertStatus(201)
        ->assertJsonPath('database.name', 'shop')
        ->assertJsonPath('database.engine', 'mysql');

    expect(Database::where('name', 'shop')->exists())->toBeTrue();
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'created']);
});

it('creates a database with a user and returns its connection string', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mysql',
        'create_user' => ['username' => 'shop_user', 'password' => 'S3cretPass99', 'connection_preference' => 'localhost'],
    ])->assertStatus(201)
        ->assertJsonPath('database.users.0.username', 'shop_user')
        ->assertJsonPath('database.users.0.password', 'S3cretPass99')
        ->assertJsonPath('database.users.0.connection_string', 'mysql://shop_user:S3cretPass99@127.0.0.1:3306/shop');
});

it('generates a password when none is given', function () {
    fakeDb();

    $res = test()->withHeaders(dbAuth())->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mysql',
        'create_user' => ['username' => 'shop_user', 'connection_preference' => 'localhost'],
    ])->assertStatus(201);

    expect(strlen((string) $res->json('database.users.0.password')))->toBeGreaterThanOrEqual(16);
});

it('rejects a system schema name', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->postJson('/api/databases', ['name' => 'mysql', 'engine' => 'mysql'])
        ->assertStatus(422)->assertJsonValidationErrorFor('name');
});

it('rejects an invalid database name', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->postJson('/api/databases', ['name' => 'bad name!', 'engine' => 'mysql'])
        ->assertStatus(422)->assertJsonValidationErrorFor('name');
});

it('rejects a collation that does not match the charset', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'latin1_swedish_ci',
    ])->assertStatus(422)->assertJsonValidationErrorFor('collation');
});

it('adds a user to an existing database', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    test()->withHeaders(dbAuth())->postJson("/api/databases/{$db->id}/users", [
        'username' => 'later_user', 'password' => 'AnotherPass88', 'connection_preference' => 'remote', 'host' => '10.0.0.5',
    ])->assertStatus(201)
        ->assertJsonPath('user.username', 'later_user')
        ->assertJsonPath('user.host', '10.0.0.5');

    expect(DatabaseUser::where('username', 'later_user')->first()->connection_preference)->toBe('remote');
});

it('rejects a system database username', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    test()->withHeaders(dbAuth())->postJson("/api/databases/{$db->id}/users", [
        'username' => 'root', 'connection_preference' => 'localhost',
    ])->assertStatus(422)->assertJsonValidationErrorFor('username');
});

it('changes a database user password', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $user = $db->users()->create(['username' => 'u', 'password' => 'old', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    test()->withHeaders(dbAuth())->putJson("/api/databases/{$db->id}/users/{$user->id}/password", ['password' => 'BrandNewPass77'])
        ->assertOk();

    expect($user->fresh()->password)->toBe('BrandNewPass77');
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'password_reset']);
});

it('deletes a database user', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $user = $db->users()->create(['username' => 'u', 'password' => 'p', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    test()->withHeaders(dbAuth())->deleteJson("/api/databases/{$db->id}/users/{$user->id}")->assertNoContent();

    expect(DatabaseUser::find($user->id))->toBeNull();
});

it('deletes a database and cascades its users', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $db->users()->create(['username' => 'u', 'password' => 'p', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    test()->withHeaders(dbAuth())->deleteJson("/api/databases/{$db->id}")->assertNoContent();

    expect(Database::find($db->id))->toBeNull();
    expect(DatabaseUser::where('database_id', $db->id)->count())->toBe(0);
});

it('lists untracked server databases', function () {
    fakeDb();
    Database::create(['name' => 'app_db', 'engine' => 'mysql']); // tracked

    test()->withHeaders(dbAuth())->getJson('/api/databases/untracked?engine=mysql')->assertOk()
        ->assertJsonPath('untracked', ['other_db']); // app_db tracked, system schemas filtered
});

it('adopts untracked databases without dropping anything', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->postJson('/api/databases/adopt', ['engine' => 'mysql', 'names' => ['other_db']])
        ->assertStatus(201)->assertJsonPath('databases.0.name', 'other_db');

    expect(Database::where('name', 'other_db')->exists())->toBeTrue();
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'imported']);
});

it('saves and tests a connection', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->putJson('/api/databases/connections/mysql', [
        'connection_type' => 'tcp', 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'root', 'test' => true,
    ])->assertOk()
        ->assertJsonPath('mysql.host', '127.0.0.1')
        ->assertJsonPath('mysql.has_password', false)
        ->assertJsonPath('mysql.reachable', true);
});

it('denies a viewer without manage from creating a database', function () {
    fakeDb();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'database', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    test()->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/databases', ['name' => 'shop', 'engine' => 'mysql'])
        ->assertForbidden();
});
