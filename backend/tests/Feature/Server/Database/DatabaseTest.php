<?php

use App\Actions\Server\Database\ExportDatabase;
use App\Contracts\DatabaseEngine;
use App\Contracts\Firewall;
use App\Enums\ExportStatus;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Jobs\InstallDatabaseEngine;
use App\Jobs\RunDatabaseExport;
use App\Models\Database;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExport;
use App\Models\DatabaseUser;
use App\Models\DbMetric;
use App\Models\FirewallRule;
use App\Models\User;
use App\Services\Server\Databases\DatabaseIdentifier;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\MongoEngine;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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
                str_contains($sql, 'information_schema.PROCESSLIST') => Process::result(output: "12\troot\tlocalhost\tshop\tQuery\t3\texecuting\tSELECT 1\n"),
                str_contains($sql, "LIKE 'max_connections'") => Process::result(output: "max_connections\t151"),
                str_contains($sql, 'SHOW GLOBAL STATUS') => Process::result(output: "Threads_connected\t5\nThreads_running\t1\nQueries\t1000\nSlow_queries\t2\nUptime\t3600"),
                str_contains($sql, 'SELECT table_name') => Process::result(output: "users\t42\t8192\nposts\t10\t4096"),
                str_contains($sql, 'SELECT COALESCE') => Process::result(output: '1048576'),
                str_contains($sql, 'SELECT 1') => Process::result(output: '1'),
                default => Process::result(exitCode: 0), // CREATE/DROP/ALTER/GRANT/RENAME/OPTIMIZE/REPAIR/KILL
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

it('names generic SQL and MongoDB client operations in the server log', function (string $engine, string $class) {
    $connection = new DatabaseConnection([
        'engine' => $engine,
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => $engine === 'mongodb' ? 27017 : 3306,
        'username' => 'panel',
        'password' => 'secret',
        'options' => [],
    ]);

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->once()->withArgs(
        fn (array $command, array $context): bool => $context['feature'] === 'database'
            && $context['engine'] === $engine
            && $context['op'] === 'query',
    )->andReturn(new ServerOpsResult(true, 'query-reference', null));

    expect((new $class($connection, $ops))->available())->toBeTrue();
})->with([
    'SQL' => ['mysql', SqlEngine::class],
    'MongoDB' => ['mongodb', MongoEngine::class],
]);

it('checks the live SQL server before approving a generated identifier', function () {
    Process::fake(fn () => Process::result(output: '0'));

    $connection = new DatabaseConnection([
        'engine' => 'mysql',
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'panel',
        'password' => 'secret',
    ]);

    $engine = new SqlEngine($connection, app(ServerOps::class));

    expect($engine->identifierAvailable('clone_shop_abc123'))->toBeFalse();

    Process::assertRan(fn ($process) => str_contains((string) $process->input, "schema_name = 'clone_shop_abc123'")
        && str_contains((string) $process->input, "user = 'clone_shop_abc123'"));
});

it('refuses to approve an identifier when the live check cannot be completed', function () {
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'unreachable'));

    $connection = new DatabaseConnection([
        'engine' => 'mysql',
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'panel',
        'password' => 'secret',
    ]);

    $engine = new SqlEngine($connection, app(ServerOps::class));

    expect(fn () => $engine->identifierAvailable('clone_shop_abc123'))
        ->toThrow(DatabaseOperationException::class);
});

it('regenerates an automatic identifier when the panel or live server already uses it', function () {
    Database::create(['name' => 'clone_shop_aaaaaa', 'engine' => 'mysql']);

    $engine = Mockery::mock(DatabaseEngine::class);
    $engine->shouldReceive('identifierAvailable')->once()->with('clone_shop_bbbbbb')->andReturnFalse();
    $engine->shouldReceive('identifierAvailable')->once()->with('clone_shop_cccccc')->andReturnTrue();

    $manager = Mockery::mock(DatabaseManager::class);
    $manager->shouldReceive('engine')->once()->with('mysql')->andReturn($engine);

    $suffixes = ['aaaaaa', 'bbbbbb', 'cccccc'];
    Str::createRandomStringsUsing(function (int $length) use (&$suffixes): string {
        return array_shift($suffixes) ?? str_repeat('z', $length);
    });

    try {
        $identifier = (new DatabaseIdentifier($manager))->generateAvailable('Shop', 'mysql', 'clone');
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($identifier)->toBe('clone_shop_cccccc');
});

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

it('rolls back a new database when its requested initial user cannot be created', function () {
    Process::fake(function ($process) {
        $sql = (string) ($process->input ?? '');

        return str_contains($sql, 'CREATE USER')
            ? Process::result(exitCode: 1, errorOutput: 'user create failed')
            : Process::result(exitCode: 0);
    });

    test()->withHeaders(dbAuth())->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mysql',
        'create_user' => ['username' => 'shop_user', 'password' => 'S3cretPass99'],
    ])->assertStatus(500);

    expect(Database::where('name', 'shop')->exists())->toBeFalse();
    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'DROP DATABASE IF EXISTS'));
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

it('removes an engine user when remote firewall setup fails', function () {
    fakeDb();
    app()->instance(Firewall::class, new class implements Firewall
    {
        public function status(): array
        {
            return ['enabled' => true, 'default_policy' => ['incoming' => 'deny', 'outgoing' => 'allow']];
        }

        public function apply(FirewallRule $rule): ServerOpsResult
        {
            return new ServerOpsResult(false, 'firewall-failed', null);
        }

        public function remove(FirewallRule $rule): ServerOpsResult
        {
            return new ServerOpsResult(true, 'unused', null);
        }

        public function enable(): ServerOpsResult
        {
            return new ServerOpsResult(true, 'unused', null);
        }

        public function disable(): ServerOpsResult
        {
            return new ServerOpsResult(true, 'unused', null);
        }
    });
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    test()->withHeaders(dbAuth())->postJson("/api/databases/{$db->id}/users", [
        'username' => 'remote_user', 'password' => 'AnotherPass88',
        'connection_preference' => 'remote', 'host' => '10.0.0.5',
    ])->assertStatus(500);

    expect(DatabaseUser::where('username', 'remote_user')->exists())->toBeFalse();
    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'CREATE USER'));
    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'DROP USER'));
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

it('keeps users and the panel record when dropping the database fails', function () {
    Process::fake(function ($process) {
        $sql = (string) ($process->input ?? '');

        return str_contains($sql, 'DROP DATABASE IF EXISTS')
            ? Process::result(exitCode: 1, errorOutput: 'drop failed')
            : Process::result(exitCode: 0);
    });
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $user = $db->users()->create(['username' => 'u', 'password' => 'p', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    test()->withHeaders(dbAuth())->deleteJson("/api/databases/{$db->id}")->assertStatus(500);

    expect(Database::find($db->id))->not->toBeNull();
    expect(DatabaseUser::find($user->id))->not->toBeNull();
    Process::assertNotRan(fn ($process) => str_contains((string) ($process->input ?? ''), 'DROP USER'));
});

it('can retry cleanup after the database was dropped but a user cleanup failed', function () {
    $dropUserFails = true;
    Process::fake(function ($process) use (&$dropUserFails) {
        $sql = (string) ($process->input ?? '');

        if (str_contains($sql, 'DROP USER') && $dropUserFails) {
            return Process::result(exitCode: 1, errorOutput: 'user drop failed');
        }

        return Process::result(exitCode: 0);
    });
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $db->users()->create(['username' => 'u', 'password' => 'p', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    test()->withHeaders(dbAuth())->deleteJson("/api/databases/{$db->id}")->assertStatus(500);
    expect(Database::find($db->id))->not->toBeNull();

    $dropUserFails = false;
    test()->withHeaders(dbAuth())->deleteJson("/api/databases/{$db->id}")->assertNoContent();

    expect(Database::find($db->id))->toBeNull();
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

// ---- P2: edit user, monitoring, maintenance ----

it('renames a database user and can change host + password in one call', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $user = $db->users()->create(['username' => 'old_u', 'password' => 'oldpw', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    test()->withHeaders(dbAuth())->patchJson("/api/databases/{$db->id}/users/{$user->id}", [
        'username' => 'new_u', 'connection_preference' => 'remote', 'host' => '10.0.0.9', 'password' => 'BrandNewPass55',
    ])->assertOk()
        ->assertJsonPath('user.username', 'new_u')
        ->assertJsonPath('user.host', '10.0.0.9');

    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), "RENAME USER 'old_u'@'localhost' TO 'new_u'@'10.0.0.9'"));
    $fresh = $user->fresh();
    expect($fresh->connection_preference)->toBe('remote');
    expect($fresh->password)->toBe('BrandNewPass55');
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'user_updated']);
});

it('lists database processes for an engine', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->getJson('/api/databases/processes?engine=mysql')->assertOk()
        ->assertJsonPath('processes.0.id', '12')
        ->assertJsonPath('processes.0.command', 'Query')
        ->assertJsonPath('processes.0.time', 3);
});

it('kills a database process (guarded) and logs it', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->deleteJson('/api/databases/processes/12?engine=mysql')->assertNoContent();

    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'KILL 12'));
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'process_killed']);
});

it('returns an engine health status', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->getJson('/api/databases/status/mysql')->assertOk()
        ->assertJsonPath('status.connections', 5)
        ->assertJsonPath('status.max_connections', 151)
        ->assertJsonPath('status.queries', 1000)
        ->assertJsonPath('status.slow_queries', 2)
        ->assertJsonPath('status.uptime_seconds', 3600);
});

it('lists tables in a database with rows and size', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    test()->withHeaders(dbAuth())->getJson("/api/databases/{$db->id}/tables")->assertOk()
        ->assertJsonPath('tables.0.name', 'users')
        ->assertJsonPath('tables.0.rows', 42)
        ->assertJsonPath('tables.0.size_bytes', 8192);
});

it('optimizes a database and logs it', function () {
    fakeDb();
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    test()->withHeaders(dbAuth())->postJson("/api/databases/{$db->id}/optimize")->assertOk();

    Process::assertRan(fn ($p) => str_contains((string) ($p->input ?? ''), 'OPTIMIZE TABLE'));
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'optimized']);
});

it('returns the QPS history from db_metrics', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
    DbMetric::create(['engine' => 'mysql', 'queries' => 1000, 'connections' => 3, 'threads_running' => 1, 'sampled_at' => Carbon::parse('2026-07-28 11:50:00')]);
    DbMetric::create(['engine' => 'mysql', 'queries' => 4000, 'connections' => 4, 'threads_running' => 2, 'sampled_at' => Carbon::parse('2026-07-28 11:55:00')]); // +3000 over 300s = 10 qps
    fakeDb();

    test()->withHeaders(dbAuth())->getJson('/api/databases/metrics/history?engine=mysql')->assertOk()
        ->assertJsonPath('metrics.0.qps', 0)   // first sample: no delta
        ->assertJsonPath('metrics.1.qps', 10); // 3000/300s

    Carbon::setTestNow();
});

it('samples db metrics into the table and prunes old rows', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
    DbMetric::create(['engine' => 'mysql', 'queries' => 1, 'connections' => 1, 'threads_running' => 0, 'sampled_at' => Carbon::parse('2026-07-26 12:00:00')]); // >24h old
    fakeDb();

    test()->artisan('db:sample-metrics')->assertExitCode(0);

    // old row pruned; fresh rows added for the reachable engines (mysql+mariadb via fake).
    expect(DbMetric::where('sampled_at', '<', Carbon::parse('2026-07-27 12:00:00'))->count())->toBe(0);
    expect(DbMetric::where('engine', 'mysql')->where('queries', 1000)->exists())->toBeTrue();

    Carbon::setTestNow();
});

// ---- P2b: export (safe, read-only) ----

it('exports a database and streams the download', function () {
    $dir = sys_get_temp_dir().'/sv-oss-exp-'.uniqid();
    config(['server.databases.export_dir' => $dir]);

    Process::fake(function ($process) {
        $cmd = $process->command;
        if (in_array($cmd[0] ?? '', ['mysqldump', 'mariadb-dump'], true)) {
            foreach ($cmd as $arg) {
                if (str_starts_with($arg, '--result-file=')) {
                    $out = substr($arg, 14);
                    @mkdir(dirname($out), 0700, true);
                    file_put_contents($out, "-- dump\nCREATE TABLE t (id INT);\n");
                }
            }
        }

        return Process::result(exitCode: 0);
    });

    // Faked so dispatch does not run inline: the point of the change is that
    // the request returns before the dump does.
    Queue::fake();

    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    // 202, not 201: the dump is queued. It outlives nginx's read timeout on any
    // real database, so the request used to be told it had failed while
    // mysqldump carried on and succeeded.
    $res = test()->withHeaders(dbAuth())->postJson("/api/databases/{$db->id}/export")->assertStatus(202);
    expect($res->json('export.status'))->toBe('queued')
        ->and($res->json('export.file'))->toBeNull()
        ->and($res->json('export.download_url'))->toBeNull();

    // Run the queued work the way a worker would.
    app(RunDatabaseExport::class, ['exportId' => $res->json('export.id'), 'databaseId' => $db->id])
        ->handle(app(ExportDatabase::class));

    $export = DatabaseExport::find($res->json('export.id'));
    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->size_bytes)->toBeGreaterThan(0);

    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'exported']);

    test()->withHeaders(dbAuth())->get("/api/databases/exports/{$export->file}")->assertOk();

    File::deleteDirectory($dir);
});

it('records a failed export instead of leaving it queued forever', function () {
    $dir = sys_get_temp_dir().'/sv-oss-exp-'.uniqid();
    config(['server.databases.export_dir' => $dir]);

    // The dump itself fails — no file is written.
    Process::fake(fn ($process) => in_array($process->command[0] ?? '', ['mysqldump', 'mariadb-dump'], true)
        ? Process::result(exitCode: 1, errorOutput: 'access denied')
        : Process::result(exitCode: 0));

    Queue::fake();

    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    $res = test()->withHeaders(dbAuth())->postJson("/api/databases/{$db->id}/export")->assertStatus(202);
    $id = $res->json('export.id');

    try {
        app(RunDatabaseExport::class, ['exportId' => $id, 'databaseId' => $db->id])->handle(app(ExportDatabase::class));
    } catch (Throwable) {
        // Rethrown so the queue marks the job failed; the row is already written.
    }

    $export = DatabaseExport::find($id);
    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->reason)->toBe('dump_failed')
        // A code, worded at read time — never a stored sentence.
        ->and($export->message())->not->toBeNull()
        ->and($export->reference)->not->toBeNull();

    File::deleteDirectory($dir);
});

it('does not strand an export at running when the worker dies', function () {
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $export = DatabaseExport::create([
        'database_id' => $db->id, 'database_name' => 'shop', 'engine' => 'mysql',
        'status' => ExportStatus::Running,
    ]);

    // Without the failed() hook the row sits at `running` forever and the
    // screen spins on something that stopped existing.
    (new RunDatabaseExport($export->id, $db->id))->failed(null);

    expect($export->refresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->reason)->toBe('worker')
        ->and($export->reference)->not->toBeEmpty();
});

it('404s a missing or traversal export filename', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->getJson('/api/databases/exports/nope-does-not-exist.sql')->assertNotFound();
    test()->withHeaders(dbAuth())->getJson('/api/databases/exports/..%2f..%2fetc%2fpasswd')->assertNotFound();
});

it('lists exports newest first, including ones still running', function () {
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);

    DatabaseExport::create([
        'database_id' => $db->id, 'database_name' => 'shop', 'engine' => 'mysql',
        'status' => ExportStatus::Completed, 'file' => 'old.sql', 'size_bytes' => 2048,
    ]);
    DatabaseExport::create([
        'database_id' => $db->id, 'database_name' => 'shop', 'engine' => 'mysql',
        'status' => ExportStatus::Running,
    ]);

    $res = test()->withHeaders(dbAuth())->getJson('/api/databases/exports')->assertOk();

    // The in-flight one is what someone who just pressed the button is looking
    // for; hiding it until it finishes is how a page looks like it did nothing.
    expect($res->json('exports.0.status'))->toBe('running')
        ->and($res->json('exports.1.status'))->toBe('completed')
        ->and($res->json('exports.1.database'))->toBe('shop')
        ->and($res->json('exports.1.size_human'))->toBe('2.0 KB');
});

it('does not offer a download for an export whose file has been removed', function () {
    config(['server.databases.export_dir' => sys_get_temp_dir().'/sv-oss-gone-'.uniqid()]);

    DatabaseExport::create([
        'database_name' => 'shop', 'engine' => 'mysql',
        'status' => ExportStatus::Completed, 'file' => 'deleted-by-hand.sql', 'size_bytes' => 10,
    ]);

    $res = test()->withHeaders(dbAuth())->getJson('/api/databases/exports')->assertOk();

    // A link that 404s is worse than saying the file has gone.
    expect($res->json('exports.0.available'))->toBeFalse()
        ->and($res->json('exports.0.download_url'))->toBeNull();
});

it('deletes an export and the file it points at', function () {
    $dir = sys_get_temp_dir().'/sv-oss-del-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/dump.sql', 'x');
    config(['server.databases.export_dir' => $dir]);

    $export = DatabaseExport::create([
        'database_name' => 'shop', 'engine' => 'mysql',
        'status' => ExportStatus::Completed, 'file' => 'dump.sql', 'size_bytes' => 1,
    ]);

    test()->withHeaders(dbAuth())->deleteJson("/api/databases/exports/{$export->id}")->assertNoContent();

    expect(File::exists($dir.'/dump.sql'))->toBeFalse()
        ->and(DatabaseExport::find($export->id))->toBeNull();
    test()->assertDatabaseHas('activity_logs', ['type' => 'database', 'action' => 'export_deleted']);

    File::deleteDirectory($dir);
});

it('deletes a failed export that never produced a file', function () {
    $export = DatabaseExport::create([
        'database_name' => 'shop', 'engine' => 'mysql',
        'status' => ExportStatus::Failed, 'reason' => 'dump_failed',
    ]);

    // Keyed by id precisely so these are removable — by filename they would sit
    // in the list forever with nothing able to clear them.
    test()->withHeaders(dbAuth())->deleteJson("/api/databases/exports/{$export->id}")->assertNoContent();

    expect(DatabaseExport::find($export->id))->toBeNull();
});

it('refuses to delete an export without manage permission', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'database', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $export = DatabaseExport::create([
        'database_name' => 'shop', 'engine' => 'mysql', 'status' => ExportStatus::Completed,
    ]);

    // Deleting a dump destroys the only copy of that data; reading the list
    // does not.
    test()->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/databases/exports/{$export->id}")->assertForbidden();

    test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/databases/exports')->assertOk();
});

// ---- size refresh ----

it('re-measures the size when a single database is shown', function () {
    fakeDb();

    // What the column looked like before: written once at creation and never
    // touched again, so a database that had grown still read as empty.
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql', 'size_bytes' => 0]);

    test()->withHeaders(dbAuth())->getJson("/api/databases/{$db->id}")->assertOk()
        ->assertJsonPath('database.size_bytes', 1048576);

    expect($db->refresh()->size_bytes)->toBe(1048576);
});

it('refreshes every tracked database size on the scheduled tick', function () {
    fakeDb();

    Database::create(['name' => 'shop', 'engine' => 'mysql', 'size_bytes' => 0]);
    Database::create(['name' => 'blog', 'engine' => 'mysql', 'size_bytes' => 0]);

    test()->artisan('databases:refresh-sizes')->assertExitCode(0);

    expect(Database::pluck('size_bytes')->all())->toBe([1048576, 1048576]);
});

it('leaves the last known size alone when the engine cannot be reached', function () {
    // Everything fails — the engine is down.
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'connection refused'));

    $db = Database::create(['name' => 'shop', 'engine' => 'mysql', 'size_bytes' => 4096]);

    test()->artisan('databases:refresh-sizes')->assertExitCode(0);

    // Writing a failed probe as 0 would report every database on a stopped
    // engine as empty — the same wrong-but-confident answer the stale column
    // used to give.
    expect($db->refresh()->size_bytes)->toBe(4096);
});

// ---- installed vs running ----

it('reports an engine that answers as both installed and running', function () {
    fakeDb();

    test()->withHeaders(dbAuth())->getJson('/api/databases/engines')->assertOk()
        ->assertJsonPath('engines.0.running', true)
        ->assertJsonPath('engines.0.installed', true);
});

it('tells a stopped engine apart from one that was never installed', function () {
    Process::fake(function ($process) {
        $bin = $process->command[0] ?? '';

        // The server is down, so nothing answers a query...
        if (in_array($bin, ['mysql', 'mariadb', 'mongosh'], true)) {
            return Process::result(exitCode: 1, errorOutput: "Can't connect to local server");
        }
        // ...but the package is still on the box.
        if ($bin === 'dpkg-query') {
            return Process::result(output: 'install ok installed');
        }

        return Process::result(exitCode: 0);
    });

    $res = test()->withHeaders(dbAuth())->getJson('/api/databases/engines')->assertOk();

    // Both of these are `running: false`. Without `installed` the UI cannot
    // tell "start the service" from "install it first".
    expect($res->json('engines.0.running'))->toBeFalse()
        ->and($res->json('engines.0.installed'))->toBeTrue()
        ->and($res->json('engines.0.version'))->toBeNull();
});

it('reports an engine that is neither running nor present as not installed', function () {
    Process::fake(function ($process) {
        $bin = $process->command[0] ?? '';

        if ($bin === 'dpkg-query') {
            return Process::result(exitCode: 1, errorOutput: 'no packages found');
        }
        if ($bin === 'which') {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 1, errorOutput: 'not found');
    });

    $res = test()->withHeaders(dbAuth())->getJson('/api/databases/engines')->assertOk();

    expect($res->json('engines.0.running'))->toBeFalse()
        ->and($res->json('engines.0.installed'))->toBeFalse();
});

it('repairs an engine that is installed but the panel cannot reach', function () {
    // "The package is present" is not "the panel can use it". Installing an
    // engine is apt *and* provisioning the account the panel connects with.
    //
    // Short-circuiting on the package alone skipped the second half, so on any
    // server where the engine was already installed — one the panel was
    // reinstalled beside, or where MariaDB predates the panel — the connection
    // stayed on the defaults (root, TCP, no password) and every query came back
    // ERROR 1698: Ubuntu's root authenticates over the unix socket, not TCP.
    // The response said `queued: false`, so the setup page had nothing to poll
    // and the install looked like it had stopped by itself, with no way to retry.
    Bus::fake();

    Process::fake(function ($process) {
        $bin = $process->command[0] ?? '';

        // The package is there...
        if ($bin === 'dpkg-query') {
            return Process::result(output: 'install ok installed');
        }

        // ...and the panel cannot authenticate against it.
        if (in_array($bin, ['mysql', 'mariadb'], true)) {
            return Process::result(
                exitCode: 1,
                errorOutput: "ERROR 1698 (28000): Access denied for user 'root'@'localhost'",
            );
        }

        return Process::result(exitCode: 0);
    });

    test()->withHeaders(dbAuth())
        ->postJson('/api/databases/engines/mariadb')
        ->assertStatus(202)
        ->assertJsonPath('queued', true);

    Bus::assertDispatched(InstallDatabaseEngine::class);
});

it('does nothing when the engine is installed and answering', function () {
    // The normal case must stay a no-op: pressing install on a working engine
    // should not re-provision it.
    Bus::fake();

    Process::fake(function ($process) {
        $bin = $process->command[0] ?? '';

        if ($bin === 'dpkg-query') {
            return Process::result(output: 'install ok installed');
        }

        if (in_array($bin, ['mysql', 'mariadb'], true)) {
            return Process::result(output: '1');
        }

        return Process::result(exitCode: 0);
    });

    test()->withHeaders(dbAuth())
        ->postJson('/api/databases/engines/mariadb')
        ->assertOk()
        ->assertJsonPath('queued', false);

    Bus::assertNotDispatched(InstallDatabaseEngine::class);
});
