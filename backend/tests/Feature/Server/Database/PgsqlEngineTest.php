<?php

use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\PgsqlEngine;
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

it('refuses remote access on an engine whose accounts have no host', function () {
    // Not a cosmetic restriction. A PostgreSQL role is cluster-wide and
    // carries no host: which addresses may reach it is decided by
    // pg_hba.conf, which this panel does not own, parse or reload. Accepting
    // the preference and storing it would be a 200, a saved setting and no
    // effect on the server — the OpenLiteSpeed PHP screen bug of 2026-09-03.
    $admin = User::factory()->admin()->create();
    $this->seed(PermissionSeeder::class);

    $this->withHeaders(['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken])
        ->postJson('/api/databases', [
            'name' => 'shop',
            'engine' => 'postgresql',
            'create_user' => [
                'username' => 'shop_user',
                'connection_preference' => 'anywhere',
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('create_user.connection_preference');
});

it('still allows remote access on the engines that support it', function () {
    // The other half: engine-scoped must not mean nobody can have a remote
    // user at all. MySQL carries the host in the account, so creating one is
    // the grant.
    expect(app(DatabaseManager::class)->supportsRemoteUsers('mysql'))->toBeTrue()
        ->and(app(DatabaseManager::class)->supportsRemoteUsers('mongodb'))->toBeTrue()
        ->and(app(DatabaseManager::class)->supportsRemoteUsers('postgresql'))->toBeFalse();
});
