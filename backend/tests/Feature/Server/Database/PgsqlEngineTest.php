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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL is a separate driver from MySQL, not a dialect of it.
 *
 * Four behaviours were measured against PostgreSQL 16, because each turns a plausible
 * translation of the MySQL code into a silent failure. Those four are what these tests pin;
 * the rest of the class is SQL text, and asserting the text back tests nothing.
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

    // SQL read from stdin that fails exits **0** without this flag, and 3 with it (`-c` fails
    // loudly, a piped script does not). The engine pipes over stdin so the password never
    // reaches argv; without ON_ERROR_STOP every must() would report success for a failed statement.
    expect(pgCommands()[0])->toContain('--set=ON_ERROR_STOP=1');
});

it('reports failure as failure', function () {
    Process::fake(fn () => Process::result(exitCode: 3, errorOutput: 'ERROR: role already exists'));

    // Exit 3 is what ON_ERROR_STOP produces. A DDL failure must reach the caller as an exception,
    // or a site is reported created with no database user.
    expect(fn () => pgEngine()->createDatabase('shop', null, null))
        ->toThrow(DatabaseOperationException::class);
});

it('makes the user own its database rather than granting on it', function () {
    pgEngine()->createUser('shop_user', 'localhost', 'pw', 'shop_db');

    $sql = pgStatements();

    // GRANT ALL PRIVILEGES ON DATABASE gives CONNECT/CREATE/TEMP and no table privileges, and
    // since PG15 PUBLIC cannot write the public schema: the account can neither read nor create
    // a table, and the application's installer fails.
    expect($sql)->toContain('ALTER DATABASE "shop_db" OWNER TO "shop_user"')
        ->and($sql)->toContain('ALTER SCHEMA public OWNER TO "shop_user"')
        ->and($sql)->not->toContain('GRANT ALL PRIVILEGES ON DATABASE');
});

it('forces a database drop past open sessions', function () {
    pgEngine()->dropDatabase('shop_db');

    // A plain DROP DATABASE is refused while any session is connected, and the site being
    // deleted usually holds one open.
    expect(pgStatements())->toContain('WITH (FORCE)');
});

it('clears what a role owns before dropping it', function () {
    pgEngine()->dropUser('shop_user', 'localhost', 'shop_db');

    $sql = pgStatements();

    // DROP ROLE is refused while the role owns anything, and it owns its database's schema.
    // Reassign first so the database survives its owner, not deleted as a side effect.
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

    // REASSIGN OWNED / DROP OWNED must run *inside* the database whose objects are owned, which
    // was just dropped, so psql could not connect. WITH (FORCE) takes the owned objects with the
    // database: nothing is left to reassign and a plain DROP ROLE succeeds.
    expect($sql)->not->toContain('REASSIGN OWNED BY')
        ->and($sql)->not->toContain('DROP OWNED BY')
        ->and($sql)->toContain('DROP DATABASE IF EXISTS "shop_db" WITH (FORCE)')
        ->and($sql)->toContain('DROP ROLE IF EXISTS "shop_user"');

    // Database first: a role dropped before a database that then fails to drop leaves a live
    // database nobody can reach.
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
    // Counterpart to the test above: here the database must survive its owner, so the reassign
    // is required.
    pgEngine()->dropUser('shop_user', 'localhost', 'shop_db');

    expect(pgStatements())->toContain('REASSIGN OWNED BY "shop_user" TO "panel_admin"');
});

it('keeps the password off argv and in a file only its owner can read', function () {
    pgEngine()->version();

    // A pgpass file with group or world access is refused and the password silently unused, so
    // 0600 decides whether authentication works at all.
    expect(implode(' ', pgCommands()))->not->toContain('secret')
        ->and(implode(' ', pgCommands()))->toContain('--no-password');
});

it('asks for output the parsers can actually read', function () {
    pgEngine()->listDatabases();

    // -t drops the header and row count, -A turns off alignment, -F sets the separator: the
    // tab-separated, header-free shape the MySQL parsers expect.
    $command = test()->ran[0]['command'];

    expect($command)->toContain('-t')
        ->and($command)->toContain('-A')
        ->and($command)->toContain("\t");
});

it('quotes identifiers, because postgres folds unquoted ones to lower case', function () {
    pgEngine()->createDatabase('Shop', null, null);

    // Unquoted, `Shop` would be created as `shop` and every later statement naming `Shop` would
    // miss it.
    expect(pgStatements())->toContain('CREATE DATABASE "Shop"');
});

it('creates from template0 when an encoding is asked for', function () {
    pgEngine()->createDatabase('shop', 'UTF8', 'C');

    // An encoding or collation different from the template's needs template0; otherwise
    // PostgreSQL refuses.
    expect(pgStatements())->toContain('TEMPLATE template0');
});

it('dumps plain SQL that carries no ownership from the machine it came from', function () {
    pgEngine()->dump('shop_db', '/tmp/shop.sql');

    $command = implode(' ', end($this->ran)['command']);

    // Plain format keeps the file a .sql the backup steps expect. --no-owner: `ALTER TABLE …
    // OWNER TO` would reinstate a role that may not exist on this server.
    expect($command)->toContain('--format=plain')
        ->and($command)->toContain('--no-owner')
        ->and($command)->toContain('--file=/tmp/shop.sql');
});

it('stops a restore that fails halfway rather than reporting success', function () {
    pgEngine()->restore('shop_db', '/tmp/shop.sql');

    // Without ON_ERROR_STOP psql replays a dump that fails partway and still exits 0: a restore
    // reporting success over a half-populated database.
    expect(implode(' ', end($this->ran)['command']))->toContain('--set=ON_ERROR_STOP=1');
});

it('is the engine the manager builds for postgresql', function () {
    // The factory matches on driver instead of falling through to SqlEngine, so an unmatched
    // driver fails loudly rather than getting a MySQL client.
    expect(app(DatabaseManager::class)->engine('postgresql'))->toBeInstanceOf(PgsqlEngine::class);
});

it('refuses to protect the wrong system databases', function () {
    $manager = app(DatabaseManager::class);

    // Inheriting MySQL's list would leave template0 droppable while protecting four schemas
    // PostgreSQL does not have.
    expect($manager->isSystemDatabase('postgresql', 'template0'))->toBeTrue()
        ->and($manager->isSystemDatabase('postgresql', 'postgres'))->toBeTrue()
        ->and($manager->isSystemDatabase('postgresql', 'information_schema'))->toBeFalse()
        ->and($manager->charsets('postgresql'))->toHaveKey('UTF8')
        ->and($manager->charsets('postgresql'))->not->toHaveKey('utf8mb4');
});

it('grants remote access rather than refusing it, now that the panel owns all three locks', function () {
    // Remote access is a pg_hba.conf record, listen_addresses and the firewall. Changing
    // listen_addresses needs a cluster restart, so the answer is a 409 asking permission.
    $admin = User::factory()->admin()->create();
    $this->seed(PermissionSeeder::class);

    // The file-wide fake answers `1` to everything, which reads as a cluster already bound
    // off-loopback; this case is about the default.
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

    // The database created alongside the user is not left behind by the refusal.
    expect(Database::where('name', 'shop')->exists())->toBeFalse();
});

it('still allows remote access on the engines that support it', function () {
    // Every engine supports it, PostgreSQL included.
    expect(app(DatabaseManager::class)->supportsRemoteUsers('mysql'))->toBeTrue()
        ->and(app(DatabaseManager::class)->supportsRemoteUsers('mongodb'))->toBeTrue()
        ->and(app(DatabaseManager::class)->supportsRemoteUsers('postgresql'))->toBeTrue();
});

it('ships the postgres client extension on every php stack', function () {
    // A PostgreSQL database is useless to a site whose PHP cannot connect to it, and the package
    // name differs per stack (php8.4-pgsql, lsphp84-pgsql). Asserted through the stack
    // abstraction, because that mapping is what would silently be wrong.
    $ops = app(ServerOps::class);

    $stacks = [
        'php8.4-pgsql' => new FpmPhpStack($ops),
        'lsphp84-pgsql' => new LsphpPhpStack($ops),
    ];

    foreach ($stacks as $expected => $stack) {
        expect($stack->extensionPackage('8.4', 'pgsql'))->toBe($expected)
            // In the base set, so a newly installed PHP version carries it.
            ->and($stack->versionPackages('8.4'))->toContain($expected);
    }
});

it('publishes whether an engine can have remote users, so no client has to name engines', function () {
    // The client must not decide by engine name, even while every engine answers `true`: the
    // next engine added is the one that needs the field, and adding it then changes the contract.
    $capabilities = collect(app(DatabaseManager::class)->capabilities())->keyBy('engine');

    expect($capabilities)->each->toHaveKey('supports_remote_users')
        ->and($capabilities['postgresql']['supports_remote_users'])->toBeTrue()
        ->and($capabilities['mysql']['supports_remote_users'])->toBeTrue()
        ->and($capabilities['mongodb']['supports_remote_users'])->toBeTrue();
});

it('reports an unmeasured counter as nothing, not as zero', function () {
    // PostgreSQL has no slow-query counter without pg_stat_statements, which the panel does not
    // install. Zero would render as "no slow queries", good news the panel has not earned.
    Process::fake(fn () => Process::result(output: "5\t100\t1\t2000\t3600"));

    expect(pgEngine()->status()['slow_queries'])->toBeNull();
});

/*
 * PostgreSQL grants CONNECT on every new database to PUBLIC, so one site's user could open
 * another site's database and list its tables. With the revoke, only the database's own users
 * connect.
 */
it('takes CONNECT away from everybody when it creates a database', function () {
    pgEngine()->createDatabase('shop', null, null);

    expect(pgStatements())->toContain('CREATE DATABASE "shop"; REVOKE CONNECT, TEMPORARY ON DATABASE "shop" FROM PUBLIC;');
});

/**
 * Answer the engine's questions about one database's owner.
 *
 * @param  array<int, string>  $otherDatabases  Other databases the owner owns.
 */
function fakePgOwner(string $owner, string $kind, bool $groupExists = false, array $otherDatabases = []): void
{
    Process::fake(function ($process) use ($owner, $kind, $groupExists, $otherDatabases) {
        $input = (string) ($process->input ?? '');
        test()->ran = [...test()->ran, ['command' => $process->command, 'input' => $input]];

        return Process::result(output: match (true) {
            str_contains($input, 'JOIN pg_roles r ON r.oid = d.datdba') => "{$owner}\t{$kind}\n",
            str_contains($input, 'SELECT 1 FROM pg_roles WHERE rolname') => $groupExists ? "1\n" : '',
            str_contains($input, 'SELECT datname FROM pg_database WHERE datdba') => implode("\n", $otherDatabases),
            default => '1',
        });
    });
}

describe('a second user on one database (bug #23)', function () {
    // A plain takeover by the second user left them "permission denied" on every table the
    // first had made, and the first without the schema.
    it('moves the first user\'s things to a shared role and makes both members', function () {
        fakePgOwner('first_user', 'login');

        pgEngine()->createUser('second_user', 'localhost', 'pw', 'shop_db');

        $sql = pgStatements();

        expect($sql)->toContain('CREATE ROLE "shop_db#owner" NOLOGIN;')
            ->and($sql)->toContain('REASSIGN OWNED BY "first_user" TO "shop_db#owner";')
            ->and($sql)->toContain('GRANT "shop_db#owner" TO "first_user";')
            ->and($sql)->toContain('GRANT "shop_db#owner" TO "second_user";')
            // Acting as the group, so whatever either user creates is shared.
            ->and($sql)->toContain('ALTER ROLE "first_user" IN DATABASE "shop_db" SET role = "shop_db#owner";')
            ->and($sql)->toContain('ALTER ROLE "second_user" IN DATABASE "shop_db" SET role = "shop_db#owner";')
            // Not the old takeover.
            ->and($sql)->not->toContain('ALTER DATABASE "shop_db" OWNER TO "second_user"');
    });

    it('does it in one transaction', function () {
        fakePgOwner('first_user', 'login');

        pgEngine()->createUser('second_user', 'localhost', 'pw', 'shop_db');

        // In steps, an application connecting halfway meets tables owned by a role it is not yet
        // acting as.
        $script = collect(test()->ran)->pluck('input')->first(fn (string $input) => str_contains($input, 'REASSIGN OWNED'));

        expect($script)->toStartWith('BEGIN;')
            ->and($script)->toEndWith('COMMIT;')
            ->and($script)->toContain('CREATE ROLE "shop_db#owner"')
            ->and($script)->toContain('SET role = "shop_db#owner"');
    });

    it('hands back the other databases the first user owns', function () {
        // REASSIGN OWNED moves every database the role owns, cluster-wide.
        fakePgOwner('first_user', 'login', otherDatabases: ['blog_db']);

        pgEngine()->createUser('second_user', 'localhost', 'pw', 'shop_db');

        $sql = pgStatements();

        expect(strpos($sql, 'ALTER DATABASE "blog_db" OWNER TO "first_user";'))
            ->toBeGreaterThan(strpos($sql, 'REASSIGN OWNED BY "first_user"'));
    });

    it('only joins a third user to the group', function () {
        fakePgOwner('shop_db#owner', 'other', groupExists: true);

        pgEngine()->createUser('third_user', 'localhost', 'pw', 'shop_db');

        $sql = pgStatements();

        expect($sql)->toContain('GRANT "shop_db#owner" TO "third_user";')
            ->and($sql)->toContain('ALTER ROLE "third_user" IN DATABASE "shop_db" SET role = "shop_db#owner";')
            ->and($sql)->not->toContain('REASSIGN OWNED')
            ->and($sql)->not->toContain('CREATE ROLE "shop_db#owner"');
    });

    it('never moves the panel\'s own account into a group', function () {
        // A database made without a user is owned by the panel's superuser;
        // REASSIGN OWNED BY it would move every database it owns.
        fakePgOwner('panel_admin', 'other');

        pgEngine()->createUser('first_user', 'localhost', 'pw', 'shop_db');

        $sql = pgStatements();

        expect($sql)->toContain('ALTER DATABASE "shop_db" OWNER TO "first_user"')
            ->and($sql)->not->toContain('REASSIGN OWNED')
            ->and($sql)->not->toContain('#owner');
    });

    it('gives a removed user\'s things to the group, not the panel', function () {
        fakePgOwner('shop_db#owner', 'other', groupExists: true);

        pgEngine()->dropUser('second_user', 'localhost', 'shop_db');

        expect(pgStatements())->toContain('REASSIGN OWNED BY "second_user" TO "shop_db#owner"');
    });

    it('drops the group with its database', function () {
        pgEngine()->teardownDatabase('shop_db', [['username' => 'first_user', 'host' => 'localhost']]);

        expect(pgStatements())->toContain('DROP ROLE IF EXISTS "shop_db#owner";');
    });

    it('names a group no user or database can share, however long the name', function () {
        $engine = pgEngine();
        $long = str_repeat('a', 62);

        // `#` is outside the username rule. PostgreSQL silently cuts names at 63 bytes, which
        // would cut the marker off and leave a role named like the database (often its own user).
        expect($engine->ownerGroup('shop_db'))->toBe('shop_db#owner')
            ->and(strlen($engine->ownerGroup($long.'x')))->toBeLessThanOrEqual(63)
            ->and($engine->ownerGroup($long.'x'))->toEndWith('#owner')
            ->and($engine->ownerGroup($long.'x'))->not->toBe($engine->ownerGroup($long.'y'));
    });
});

it('grants nothing extra when the user already owns the database', function () {
    Process::fake(function ($process) {
        $this->ran[] = ['command' => $process->command, 'input' => (string) ($process->input ?? '')];

        return Process::result(output: str_contains((string) $process->input, 'pg_get_userbyid') ? "shop_user\n" : '1');
    });

    pgEngine()->createUser('shop_user', 'localhost', 'pw', 'shop_db');

    expect(pgStatements())->not->toContain('GRANT CONNECT');
});

describe('a restore gives the database back to its users', function () {
    /**
     * Answer the engine's questions for a restore of `shop_db`.
     *
     * @param  array<int, string>  $roles  The recorded users that exist as roles.
     * @param  array<int, string>  $trusted  Extensions the server marks trusted.
     */
    function fakePgRestore(string $owner = 'shop_user', string $kind = 'login', array $roles = [], bool $groupExists = false, array $trusted = []): void
    {
        Process::fake(function ($process) use ($owner, $kind, $roles, $groupExists, $trusted) {
            $input = (string) ($process->input ?? '');
            test()->ran = [...test()->ran, ['command' => $process->command, 'input' => $input]];

            return Process::result(output: match (true) {
                str_contains($input, 'JOIN pg_roles r ON r.oid = d.datdba') => "{$owner}\t{$kind}\n",
                str_contains($input, 'SELECT rolname FROM pg_roles WHERE rolname IN') => implode("\n", $roles),
                str_contains($input, 'SELECT 1 FROM pg_roles WHERE rolname') => $groupExists ? "1\n" : '',
                str_contains($input, 'pg_available_extension_versions') => implode("\n", $trusted),
                default => '',
            });
        });
    }

    function pgDump(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pgdump');
        file_put_contents($path, $contents);

        return $path;
    }

    /** The argv of the psql run that loads the dump. */
    function loadCommand(): array
    {
        return collect(test()->ran)->pluck('command')->first(
            fn (array $command) => collect($command)->contains(fn ($arg) => str_starts_with((string) $arg, '--file=')),
        );
    }

    it('makes a single user the owner again', function () {
        fakePgRestore(roles: ['shop_user']);

        pgEngine()->reattachUsers('shop_db', ['shop_user']);

        expect(pgStatements())->toContain('BEGIN; ALTER DATABASE "shop_db" OWNER TO "shop_user"; ALTER SCHEMA public OWNER TO "shop_user";')
            ->and(pgStatements())->not->toContain('#owner');
    });

    it('gives a shared database back to its group, and every user back to the group', function () {
        fakePgRestore(roles: ['one', 'two'], groupExists: true);

        pgEngine()->reattachUsers('shop_db', ['one', 'two']);

        $sql = pgStatements();

        expect($sql)->toContain('ALTER DATABASE "shop_db" OWNER TO "shop_db#owner";')
            ->and($sql)->toContain('ALTER ROLE "one" IN DATABASE "shop_db" SET role = "shop_db#owner";')
            ->and($sql)->toContain('ALTER ROLE "two" IN DATABASE "shop_db" SET role = "shop_db#owner";')
            ->and($sql)->toContain('GRANT CONNECT, TEMPORARY ON DATABASE "shop_db" TO "two";')
            ->and($sql)->not->toContain('CREATE ROLE "shop_db#owner"');
    });

    it('creates the group for several users from before there was one', function () {
        fakePgRestore(roles: ['one', 'two']);

        pgEngine()->reattachUsers('shop_db', ['one', 'two']);

        expect(pgStatements())->toContain('CREATE ROLE "shop_db#owner" NOLOGIN;');
    });

    it('leaves out a recorded user whose role is gone', function () {
        // One dropped outside the panel would fail the whole transaction, after the database was
        // already dropped for the restore.
        fakePgRestore(roles: ['one']);

        pgEngine()->reattachUsers('shop_db', ['one', 'gone']);

        expect(pgStatements())->toContain('ALTER DATABASE "shop_db" OWNER TO "one";')
            ->and(pgStatements())->not->toContain('"gone"');
    });

    it('does nothing for a database with no users', function () {
        fakePgRestore();

        pgEngine()->reattachUsers('shop_db', []);

        expect(test()->ran)->toBe([]);
    });

    it('loads the dump as the owner, so what it creates is theirs', function () {
        fakePgRestore();

        pgEngine()->restore('shop_db', pgDump("CREATE TABLE t (i int);\n"));

        $command = loadCommand();
        $setRole = array_search('--command=SET ROLE "shop_user";', $command, true);
        $file = collect($command)->search(fn ($arg) => str_starts_with((string) $arg, '--file='));

        // Before the file: psql runs them in the order given, in one session.
        expect($setRole)->not->toBeFalse()->and($setRole)->toBeLessThan($file);
    });

    it('loads as the panel when the panel owns the database', function () {
        fakePgRestore(owner: 'panel_admin', kind: 'super');

        pgEngine()->restore('shop_db', pgDump("CREATE TABLE t (i int);\n"));

        expect(implode(' ', loadCommand()))->not->toContain('SET ROLE');
    });

    it('loads as the owner when every extension the dump needs is trusted', function () {
        fakePgRestore(trusted: ['pg_trgm']);

        pgEngine()->restore('shop_db', pgDump("CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;\nCOMMENT ON EXTENSION pg_trgm IS 'x';\n"));

        expect(implode(' ', loadCommand()))->toContain('SET ROLE "shop_user"');
    });

    it('loads as the panel when the dump needs an extension only a superuser may create', function () {
        // As the owner, the dump's COMMENT ON EXTENSION would fail, and so would an undo from a
        // safety backup holding the same one.
        fakePgRestore(trusted: ['pg_trgm']);

        pgEngine()->restore('shop_db', pgDump("CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;\nCREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;\n"));

        expect(implode(' ', loadCommand()))->not->toContain('SET ROLE');
    });
});

describe('a server without PostgreSQL (2026-11-05)', function () {
    // `env` exits 127 when psql is not installed; reads must not log that as a failed command
    // and fill the Error Log of servers without PostgreSQL.
    beforeEach(function () {
        Process::fake(['*' => Process::result(exitCode: 127, errorOutput: "env: 'psql': No such file or directory")]);
        $this->logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('server-ops')->andReturn($this->logger);
    });

    it('answers "no version" without an error in the log', function () {
        expect(pgEngine()->version())->toBeNull();

        $this->logger->shouldHaveReceived('info')->once()->with('server operation', Mockery::on(
            fn (array $context): bool => $context['op'] === 'query' && $context['exit_code'] === 127 && $context['expected_exit'] === true,
        ));
        $this->logger->shouldNotHaveReceived('error');
    });

    it('answers "not available" without an error in the log', function () {
        expect(pgEngine()->available())->toBeFalse();

        $this->logger->shouldNotHaveReceived('error');
    });

    it('still logs a real operation that fails the same way as an error', function () {
        expect(pgEngine()->listDatabases())->toBe([]);

        $this->logger->shouldHaveReceived('error')->once()->with('server operation', Mockery::on(
            fn (array $context): bool => $context['op'] === 'query' && $context['expected_exit'] === false,
        ));
    });
});
