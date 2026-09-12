<?php

use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\Database;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\PgsqlEngine;
use App\Services\Server\Php\Stacks\FpmPhpStack;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\ServerOps;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * PostgreSQL is a separate driver from MySQL, not a dialect of it.
 *
 * Four behaviours were measured against PostgreSQL 16 before this engine was
 * written (2026-09-09), because each turns a plausible translation of the MySQL
 * code into a silent failure. Those four are what these tests pin — the rest of
 * the class is SQL text, and a test asserting the text back is a test of
 * nothing.
 */
beforeEach(function () {
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);

    $this->ran = [];

    Process::fake(function ($process) {
        $this->ran[] = [
            'command' => $process->command,
            'input' => (string) ($process->input ?? ''),
        ];

        return Process::result(output: '1');
    });
});

function pgEngine(array $overrides = []): PgsqlEngine
{
    return new PgsqlEngine(
        new DatabaseConnection(array_merge([
            'engine' => 'postgresql',
            'connection_type' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 5432,
            'username' => 'panel_admin',
            'password' => 'secret',
        ], $overrides)),
        app(ServerOps::class),
    );
}

/** Every psql invocation, flattened. */
function pgCommands(): array
{
    return array_map(fn (array $run) => implode(' ', $run['command']), test()->ran);
}

/** Every statement piped to psql, flattened. */
function pgStatements(): string
{
    return implode("\n", array_column(test()->ran, 'input'));
}

it('stops on the first error, because psql does not', function () {
    pgEngine()->listDatabases();

    // The measurement this whole class rests on: SQL read from stdin that
    // fails exits **0** without this flag, and 3 with it. `-c` fails loudly;
    // a piped script does not. SqlEngine pipes over stdin deliberately, so the
    // password never reaches argv, and this engine does the same — which means
    // without ON_ERROR_STOP every must() would report success for a statement
    // that never ran.
    expect(pgCommands()[0])->toContain('--set=ON_ERROR_STOP=1');
});

it('reports failure as failure', function () {
    Process::fake(fn () => Process::result(exitCode: 3, errorOutput: 'ERROR: role already exists'));

    // Exit 3 is what ON_ERROR_STOP produces. A DDL failure has to reach the
    // caller as an exception; the alternative is a site reported created with
    // no database user.
    expect(fn () => pgEngine()->createDatabase('shop', null, null))
        ->toThrow(DatabaseOperationException::class);
});

it('makes the user own its database rather than granting on it', function () {
    pgEngine()->createUser('shop_user', 'localhost', 'pw', 'shop_db');

    $sql = pgStatements();

    // Measured both ways: GRANT ALL PRIVILEGES ON DATABASE leaves the account
    // unable to read a table *or* create one — it carries CONNECT/CREATE/TEMP
    // and no table privileges, and since PG15 the public schema is not
    // writable by PUBLIC either. The application then fails inside its own
    // installer, long after the panel reported the site created.
    expect($sql)->toContain('ALTER DATABASE "shop_db" OWNER TO "shop_user"')
        ->and($sql)->toContain('ALTER SCHEMA public OWNER TO "shop_user"')
        ->and($sql)->not->toContain('GRANT ALL PRIVILEGES ON DATABASE');
});

it('forces a database drop past open sessions', function () {
    pgEngine()->dropDatabase('shop_db');

    // Measured: a plain DROP DATABASE is refused while any session is
    // connected, and the site being deleted is usually what holds one open.
    expect(pgStatements())->toContain('WITH (FORCE)');
});

it('clears what a role owns before dropping it', function () {
    pgEngine()->dropUser('shop_user', 'localhost', 'shop_db');

    $sql = pgStatements();

    // Measured: DROP ROLE is refused outright while the role owns anything,
    // and by this point it owns its database's schema. Reassign first so the
    // database survives its owner — dropping it here would delete a site's
    // data as a side effect of removing a user.
    expect($sql)->toContain('REASSIGN OWNED BY "shop_user" TO "panel_admin"')
        ->and($sql)->toContain('DROP OWNED BY "shop_user"')
        ->and($sql)->toContain('DROP ROLE IF EXISTS "shop_user"');

    $reassign = strpos($sql, 'DROP OWNED BY');
    $drop = strpos($sql, 'DROP ROLE');
    expect($reassign)->toBeLessThan($drop);
});

it('does not reassign into a database it has just dropped', function () {
    pgEngine()->teardownDatabase('shop_db', [
        ['username' => 'shop_user', 'host' => 'localhost'],
    ]);

    $sql = pgStatements();

    // The bug this replaces: teardown called dropUser() in a loop, and that
    // method's REASSIGN OWNED / DROP OWNED has to run *inside* the database
    // whose objects are owned — which the line before had just dropped. psql
    // could not connect, so the cleanup meant to make DROP ROLE possible was
    // itself what failed, with a 500 and a panel row left behind.
    //
    // WITH (FORCE) takes the owned objects with the database, so there is
    // nothing left to reassign and a plain DROP ROLE succeeds.
    expect($sql)->not->toContain('REASSIGN OWNED BY')
        ->and($sql)->not->toContain('DROP OWNED BY')
        ->and($sql)->toContain('DROP DATABASE IF EXISTS "shop_db" WITH (FORCE)')
        ->and($sql)->toContain('DROP ROLE IF EXISTS "shop_user"');

    // Database first: a role dropped before the database that failed to drop
    // would leave a live database nobody can reach.
    expect(strpos($sql, 'DROP DATABASE'))->toBeLessThan(strpos($sql, 'DROP ROLE'));

    // psql must never be pointed at the database being torn down.
    foreach (test()->ran as $call) {
        expect($call['command'])->not->toContain('--dbname=shop_db');
    }
});

it('drops every user of the database, not only the first', function () {
    pgEngine()->teardownDatabase('shop_db', [
        ['username' => 'shop_user', 'host' => 'localhost'],
        ['username' => 'shop_readonly', 'host' => '%'],
    ]);

    expect(pgStatements())->toContain('DROP ROLE IF EXISTS "shop_user"')
        ->and(pgStatements())->toContain('DROP ROLE IF EXISTS "shop_readonly"');
});

it('still clears what a role owns when only the user is being removed', function () {
    // The counterpart to the test above, and the reason the two paths are not
    // the same method: here the database must survive its owner, so the
    // reassign is required rather than impossible.
    pgEngine()->dropUser('shop_user', 'localhost', 'shop_db');

    expect(pgStatements())->toContain('REASSIGN OWNED BY "shop_user" TO "panel_admin"');
});

it('keeps the password off argv and in a file only its owner can read', function () {
    pgEngine()->version();

    // Measured: a pgpass file with group or world access is refused with a
    // warning and the password is simply not used — so 0600 is not tidiness,
    // it is the difference between authenticating and not.
    expect(implode(' ', pgCommands()))->not->toContain('secret')
        ->and(implode(' ', pgCommands()))->toContain('--no-password');
});

it('asks for output the parsers can actually read', function () {
    pgEngine()->listDatabases();

    // -t drops the header and row count, -A turns off alignment, -F sets the
    // separator: together they give the tab-separated, header-free shape the
    // MySQL parsers already expect, which is what let this engine reuse them.
    $command = test()->ran[0]['command'];

    expect($command)->toContain('-t')
        ->and($command)->toContain('-A')
        ->and($command)->toContain("\t");
});

it('quotes identifiers, because postgres folds unquoted ones to lower case', function () {
    pgEngine()->createDatabase('Shop', null, null);

    // Unquoted, `Shop` would be created as `shop` and every later statement
    // naming `Shop` would miss it. MySQL's back-ticks are optional; these are
    // not.
    expect(pgStatements())->toContain('CREATE DATABASE "Shop"');
});

it('creates from template0 when an encoding is asked for', function () {
    pgEngine()->createDatabase('shop', 'UTF8', 'C');

    // A database can only take an encoding or collation different from its
    // template's when that template is template0. Without this PostgreSQL
    // refuses outright, so the charset field would fail every time it was used.
    expect(pgStatements())->toContain('TEMPLATE template0');
});

it('dumps plain SQL that carries no ownership from the machine it came from', function () {
    pgEngine()->dump('shop_db', '/tmp/shop.sql');

    $command = implode(' ', end($this->ran)['command']);

    // Plain format keeps the file a .sql the backup steps already name, size
    // and sanity-check. --no-owner because a dump carrying `ALTER TABLE …
    // OWNER TO` would reinstate a role that need not exist on this server.
    expect($command)->toContain('--format=plain')
        ->and($command)->toContain('--no-owner')
        ->and($command)->toContain('--file=/tmp/shop.sql');
});

it('stops a restore that fails halfway rather than reporting success', function () {
    pgEngine()->restore('shop_db', '/tmp/shop.sql');

    // The same measurement as the first test, where it costs the most: without
    // this psql replays a dump that fails partway and still exits 0 — a
    // restore reporting success over a half-populated database.
    expect(implode(' ', end($this->ran)['command']))->toContain('--set=ON_ERROR_STOP=1');
});

it('is the engine the manager builds for postgresql', function () {
    // The factory matches on driver rather than falling through to SqlEngine,
    // so an unmatched driver is a loud failure instead of a MySQL client
    // pointed at an engine that does not speak MySQL.
    expect(app(DatabaseManager::class)->engine('postgresql'))->toBeInstanceOf(PgsqlEngine::class);
});

it('refuses to protect the wrong system databases', function () {
    $manager = app(DatabaseManager::class);

    // The failure 3ceb3452 exists to prevent, now with a real fourth engine:
    // inheriting MySQL's list would leave template0 droppable while
    // protecting four schemas PostgreSQL does not have.
    expect($manager->isSystemDatabase('postgresql', 'template0'))->toBeTrue()
        ->and($manager->isSystemDatabase('postgresql', 'postgres'))->toBeTrue()
        ->and($manager->isSystemDatabase('postgresql', 'information_schema'))->toBeFalse()
        ->and($manager->charsets('postgresql'))->toHaveKey('UTF8')
        ->and($manager->charsets('postgresql'))->not->toHaveKey('utf8mb4');
});

it('grants remote access rather than refusing it, now that the panel owns all three locks', function () {
    // This test used to assert a 422. The refusal was right while the feature
    // was unimplemented — accepting a preference nothing applies is the
    // OpenLiteSpeed PHP screen bug of 2026-09-03, a 200 and no effect on the
    // server. It is implemented now: a pg_hba.conf record, listen_addresses,
    // and the firewall. So the refusal is gone and what replaces it is a 409
    // asking permission to restart the cluster, because listen_addresses
    // cannot be changed without one.
    $admin = User::factory()->admin()->create();
    $this->seed(PermissionSeeder::class);

    // The file-wide fake answers `1` to everything, which reads as a cluster
    // already bound off-loopback. This case is about the default, so it has to
    // say so.
    Process::fake(function ($process) {
        return str_contains((string) ($process->input ?? ''), 'SHOW listen_addresses')
            ? Process::result(output: 'localhost')
            : Process::result(output: '1');
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken])
        ->postJson('/api/databases', [
            'name' => 'shop',
            'engine' => 'postgresql',
            'create_user' => [
                'username' => 'shop_user',
                'connection_preference' => 'anywhere',
            ],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'restart_required');

    // And the database it was asked to create alongside the user is not left
    // behind by the refusal.
    expect(Database::where('name', 'shop')->exists())->toBeFalse();
});

it('still allows remote access on the engines that support it', function () {
    // Every engine now does, PostgreSQL included — it was the last one, and it
    // took owning pg_hba.conf and listen_addresses to get there.
    expect(app(DatabaseManager::class)->supportsRemoteUsers('mysql'))->toBeTrue()
        ->and(app(DatabaseManager::class)->supportsRemoteUsers('mongodb'))->toBeTrue()
        ->and(app(DatabaseManager::class)->supportsRemoteUsers('postgresql'))->toBeTrue();
});

it('ships the postgres client extension on every php stack', function () {
    // The panel supports four web servers and two PHP stacks. A PostgreSQL
    // database the panel can create is useless to a site whose PHP cannot
    // connect to it, and the package name differs per stack — php8.4-pgsql
    // against lsphp84-pgsql.
    //
    // Asserted through the stack abstraction rather than by reading the config
    // list, because that mapping is the part that would silently be wrong.
    $ops = app(ServerOps::class);

    $stacks = [
        'php8.4-pgsql' => new FpmPhpStack($ops),
        'lsphp84-pgsql' => new LsphpPhpStack($ops),
    ];

    foreach ($stacks as $expected => $stack) {
        expect($stack->extensionPackage('8.4', 'pgsql'))->toBe($expected)
            // In the base set, so a newly installed PHP version carries it
            // rather than the user discovering the gap from a driver error.
            ->and($stack->versionPackages('8.4'))->toContain($expected);
    }
});

it('publishes whether an engine can have remote users, so no client has to name engines', function () {
    // Reported by the frontend, 2026-09-10: my own handoff told them "for
    // PostgreSQL, show only localhost", which is an engine name typed into
    // client code — exactly what 3ceb3452 removed from the backend. The value
    // already existed; it just was not sent.
    //
    // Every engine answers `true` as of 2026-09-12, PostgreSQL included. The
    // field stays, and so does this test: it exists because the client must not
    // decide by engine name, and that is just as true when the answer is
    // currently the same everywhere. The next engine the panel adds is the one
    // that needs it, and a field added then would be a contract change.
    $capabilities = collect(app(DatabaseManager::class)->capabilities())->keyBy('engine');

    expect($capabilities)->each->toHaveKey('supports_remote_users')
        ->and($capabilities['postgresql']['supports_remote_users'])->toBeTrue()
        ->and($capabilities['mysql']['supports_remote_users'])->toBeTrue()
        ->and($capabilities['mongodb']['supports_remote_users'])->toBeTrue();
});

it('reports an unmeasured counter as nothing, not as zero', function () {
    // PostgreSQL has no slow-query counter without pg_stat_statements, which
    // the panel does not install. Zero renders as "no slow queries" — good
    // news the panel has not earned. MongoEngine already answered null here;
    // zero was an inconsistency I introduced.
    Process::fake(fn () => Process::result(output: "5\t100\t1\t2000\t3600"));

    expect(pgEngine()->status()['slow_queries'])->toBeNull();
});
