<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * PostgreSQL via `psql`. A separate driver from {@see SqlEngine}, not a
 * subclass of it: the two share the word "SQL" and almost nothing else that
 * matters here.
 *
 * Four differences were measured against PostgreSQL 16 before this was written
 * (2026-09-09), because each of them turns a plausible translation of the MySQL
 * code into a silent failure:
 *
 *  1. **`psql` exits 0 after a failed statement read from stdin.** Not 1 — zero.
 *     `-c` fails loudly, a piped script does not. `SqlEngine` pipes over stdin
 *     deliberately, so the password never reaches argv, and this class does the
 *     same. Without `ON_ERROR_STOP=1` every {@see must()} would report success
 *     for a `CREATE USER` that never ran. It is not a nicety; it is the reason
 *     this class can be trusted at all, and a test fails when it is removed.
 *
 *  2. **`GRANT ALL PRIVILEGES ON DATABASE` grants nothing useful.** It carries
 *     CONNECT/CREATE/TEMP and not one table privilege, and since PG15 the
 *     `public` schema is not writable by PUBLIC either — so the literal
 *     translation of MySQL's `GRANT ALL ON db.* TO user` produces an account
 *     that can neither read a table nor create one. Measured, both ways. The
 *     working model is **ownership**: the role owns the database and its
 *     `public` schema.
 *
 *  3. **Teardown refuses more often than MySQL's.** `DROP DATABASE` fails while
 *     any session is connected, and `DROP ROLE` fails while the role owns
 *     anything. Hence `WITH (FORCE)` and a `DROP OWNED BY` first. Without them
 *     a delete half-completes, which is how a site ends up removed from the
 *     panel and still present on the server.
 *
 *  4. **A role is cluster-wide and has no host.** MySQL's identity is
 *     `'user'@'host'`; here the host half lives in `pg_hba.conf`, a file this
 *     panel does not own. Every `$host` argument below is therefore accepted
 *     and ignored, and the request layer refuses to *offer* remote access for
 *     this engine rather than accepting a setting nothing would apply.
 *
 * Credentials go in a 0600 `PGPASSFILE` (measured: 0644 is warned about and
 * ignored), never on argv. Identifiers are double-quoted and validated upstream
 * by the FormRequests; string literals are single-quoted and escaped.
 */
class PgsqlEngine implements DatabaseEngine
{
    /**
     * The database `psql` connects *to* in order to run administrative
     * statements. `CREATE DATABASE` cannot run from inside the database it is
     * creating, and cannot run in a transaction block either, so every
     * statement here is sent to the cluster's maintenance database.
     */
    private const MAINTENANCE_DATABASE = 'postgres';

    public function __construct(
        private DatabaseConnection $connection,
        private ServerOps $serverOps,
    ) {}

    public function engine(): string
    {
        return $this->connection->engine;
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function available(): bool
    {
        return $this->run('SELECT 1;')->ok;
    }

    public function version(): ?string
    {
        // `server_version` rather than `version()`: the latter is a sentence
        // including the compiler and platform, and the screen wants a number.
        $result = $this->run('SHOW server_version;');

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    public function listDatabases(): array
    {
        // `datallowconn` excludes template0, which exists but cannot be
        // connected to at all — offering it would be offering a database no
        // operation could ever touch.
        $result = $this->run(
            'SELECT datname FROM pg_database WHERE datistemplate = false AND datallowconn = true ORDER BY datname;'
        );

        if ($result->failed()) {
            return [];
        }

        $system = (array) config("server.databases.drivers.{$this->driver()}.system_schemas", []);

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($result->output())) ?: []),
            fn (string $db) => $db !== '' && ! in_array($db, $system, true),
        ));
    }

    public function listUsers(): array
    {
        // `rolcanlogin` — a role that cannot log in is a group, not an account,
        // and listing it as a database user on a migrated server invites
        // someone to hand it a password it will never use.
        $result = $this->run(
            'SELECT rolname FROM pg_roles WHERE rolcanlogin = true ORDER BY rolname;'
        );

        if ($result->failed()) {
            return [];
        }

        $system = (array) config('server.databases.system_users', []);
        $users = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $username = trim($line);

            if ($username === '' || in_array($username, $system, true)) {
                continue;
            }

            $users[] = [
                'username' => $username,
                // Always localhost: a role has no host of its own. Reported
                // rather than left blank so the shape matches the other
                // engines and the adopt screen has something to show.
                'host' => 'localhost',
                'databases' => $this->reachableDatabases($username),
            ];
        }

        return $users;
    }

    public function identifierAvailable(string $name, string $host = 'localhost'): bool
    {
        $result = $this->run(sprintf(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_database WHERE datname = %s) '
            .'OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = %s) THEN 0 ELSE 1 END;',
            $this->literal($name),
            $this->literal($name),
        ));

        // A strict preflight: an unreachable server must throw rather than look
        // empty and falsely approve a name somebody else already has.
        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === '1';
    }

    /**
     * The databases an account can actually connect to.
     *
     * `has_database_privilege` rather than parsing an ACL column: the answer
     * accounts for roles the account is a member of, which a literal reading of
     * `datacl` does not.
     *
     * @return array<int, string>
     */
    private function reachableDatabases(string $username): array
    {
        $result = $this->run(sprintf(
            'SELECT datname FROM pg_database WHERE datistemplate = false AND datallowconn = true '
            .'AND has_database_privilege(%s, datname, %s) ORDER BY datname;',
            $this->literal($username),
            $this->literal('CONNECT'),
        ));

        if ($result->failed()) {
            return [];
        }

        $system = (array) config("server.databases.drivers.{$this->driver()}.system_schemas", []);

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($result->output())) ?: []),
            fn (string $db) => $db !== '' && ! in_array($db, $system, true),
        ));
    }

    /**
     * `$charset` is PostgreSQL's ENCODING; `$collation` is LC_COLLATE.
     *
     * `TEMPLATE template0` whenever either is given, because a database can
     * only be created with an encoding or collation different from the
     * template's if that template is template0 — the one template guaranteed to
     * contain nothing locale-specific. Without it PostgreSQL refuses outright,
     * which would make the charset field on the create form fail every time it
     * was used.
     */
    public function createDatabase(string $name, ?string $charset, ?string $collation): void
    {
        $sql = 'CREATE DATABASE '.$this->ident($name);

        if ($charset) {
            $sql .= ' ENCODING '.$this->literal($charset);
        }

        if ($collation) {
            $sql .= ' LC_COLLATE '.$this->literal($collation).' LC_CTYPE '.$this->literal($collation);
        }

        if ($charset || $collation) {
            $sql .= ' TEMPLATE template0';
        }

        $this->must($sql.';');
    }

    /**
     * `WITH (FORCE)` — PostgreSQL 13+ — because a plain DROP DATABASE fails
     * while any session is connected, and the site being deleted is usually
     * the thing holding a connection open. Measured: refused without it,
     * succeeds with it.
     *
     * `IF EXISTS` for the same reason as the MySQL engine: a deletion can have
     * dropped the database and failed while cleaning up its role, and retrying
     * must finish that cleanup rather than fail here.
     */
    public function dropDatabase(string $name): void
    {
        $this->must('DROP DATABASE IF EXISTS '.$this->ident($name).' WITH (FORCE);');
    }

    public function databaseSize(string $name): int
    {
        $result = $this->run(sprintf(
            'SELECT COALESCE(pg_database_size(%s), 0);',
            $this->literal($name),
        ));

        return $result->ok ? (int) trim($result->output()) : 0;
    }

    /**
     * Create the role and give it ownership of its one database.
     *
     * Ownership, not `GRANT ALL PRIVILEGES ON DATABASE`. That grant was
     * measured to leave the account unable to read a table *or* create one —
     * it carries CONNECT/CREATE/TEMP and no table privileges, and since PG15
     * the `public` schema is not writable by PUBLIC either. An application
     * handed such an account fails inside its own installer, long after the
     * panel has reported the site created.
     *
     * The schema is re-owned in a second connection because `ALTER SCHEMA`
     * has to run *inside* the database that holds the schema, while
     * `CREATE ROLE` and `ALTER DATABASE` do not.
     *
     * `$host` is ignored: a role is cluster-wide. See the class docblock.
     */
    public function createUser(string $username, string $host, string $password, string $database): void
    {
        $this->must(sprintf(
            'CREATE ROLE %s WITH LOGIN PASSWORD %s;',
            $this->ident($username),
            $this->literal($password),
        ));

        $this->must(sprintf(
            'ALTER DATABASE %s OWNER TO %s;',
            $this->ident($database),
            $this->ident($username),
        ));

        $this->mustIn($database, sprintf(
            'ALTER SCHEMA public OWNER TO %s; GRANT ALL ON SCHEMA public TO %s;',
            $this->ident($username),
            $this->ident($username),
        ));
    }

    /**
     * `DROP OWNED BY` first — measured: `DROP ROLE` is refused outright while
     * the role owns anything, and by this point it owns the database's schema.
     * That statement has to run inside the database whose objects are owned,
     * so it is a separate connection.
     *
     * The database itself is handed back to the connection's own account
     * rather than dropped: dropping a database here would delete a site's data
     * as a side effect of removing one of its users.
     */
    public function dropUser(string $username, string $host, string $database): void
    {
        $admin = (string) $this->connection->username;

        // Reassign before dropping so the database survives its owner. Both
        // are sent together: a REASSIGN that succeeds and a DROP OWNED that
        // does not would leave the role undroppable and the reason obscure.
        $this->mustIn($database, sprintf(
            'REASSIGN OWNED BY %s TO %s; DROP OWNED BY %s;',
            $this->ident($username),
            $this->ident($admin),
            $this->ident($username),
        ));

        $this->must('DROP ROLE IF EXISTS '.$this->ident($username).';');
    }

    public function setPassword(string $username, string $host, string $password, string $database): void
    {
        $this->must(sprintf(
            'ALTER ROLE %s WITH PASSWORD %s;',
            $this->ident($username),
            $this->literal($password),
        ));
    }

    /**
     * Rename in place. Measured on PostgreSQL 16: the password survives a
     * rename under scram-sha-256, which is the default — so `$password` is
     * unused here and the driver reports `rename_keeps_password`, which is what
     * makes the caller issue its own `setPassword()` when one was requested.
     *
     * (Under the long-deprecated md5 encoding a rename clears the password,
     * because the username is part of the hash. A cluster configured that way
     * would need the password set again; nothing the panel installs does so.)
     */
    public function renameUser(string $username, string $host, string $newUsername, string $newHost, string $password, string $database): void
    {
        $this->must(sprintf(
            'ALTER ROLE %s RENAME TO %s;',
            $this->ident($username),
            $this->ident($newUsername),
        ));
    }

    /**
     * Live backends, excluding this connection.
     *
     * `pid <> pg_backend_pid()` for the reason the MySQL engine excludes its
     * own thread: without it the panel is always in its own list, an idle
     * server never reads as idle, and the Stop button is offered for a backend
     * that has already gone by the time the kill is issued.
     */
    public function processes(): array
    {
        $result = $this->run(
            "SELECT pid, COALESCE(usename, ''), COALESCE(client_addr::text, 'local'), "
            ."COALESCE(datname, 'NULL'), COALESCE(state, ''), "
            .'COALESCE(EXTRACT(EPOCH FROM (now() - query_start))::bigint, 0), '
            ."COALESCE(wait_event_type, ''), COALESCE(NULLIF(query, ''), 'NULL') "
            .'FROM pg_stat_activity WHERE pid <> pg_backend_pid() ORDER BY query_start;'
        );

        if ($result->failed()) {
            return [];
        }

        $processes = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $c = explode("\t", $line);

            $processes[] = [
                'id' => $c[0] ?? '',
                'user' => $c[1] ?? '',
                'host' => $c[2] ?? '',
                'db' => ($c[3] ?? '') === 'NULL' ? null : ($c[3] ?? null),
                // `state` carries what MySQL calls Command (active/idle/…),
                // and `wait_event_type` is the nearer match for its State.
                'command' => $c[4] ?? '',
                'time' => (int) ($c[5] ?? 0),
                'state' => $c[6] ?? '',
                'query' => ($c[7] ?? '') === 'NULL' ? null : ($c[7] ?? null),
            ];
        }

        return $processes;
    }

    /**
     * `pg_terminate_backend`, not `pg_cancel_backend`: the control is labelled
     * as ending the session, and cancel only stops the current statement while
     * leaving the connection open — which looks like the button did nothing.
     */
    public function killProcess(string $id): void
    {
        $this->must('SELECT pg_terminate_backend('.(int) $id.');');
    }

    public function status(): array
    {
        $result = $this->run(
            'SELECT (SELECT count(*) FROM pg_stat_activity), '
            ."(SELECT setting::bigint FROM pg_settings WHERE name = 'max_connections'), "
            ."(SELECT count(*) FROM pg_stat_activity WHERE state = 'active'), "
            .'(SELECT COALESCE(sum(xact_commit + xact_rollback), 0) FROM pg_stat_database), '
            .'(SELECT EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))::bigint);'
        );

        if ($result->failed()) {
            return [];
        }

        $c = explode("\t", trim($result->output()));

        return [
            'connections' => (int) ($c[0] ?? 0),
            'max_connections' => (int) ($c[1] ?? 0),
            'threads_running' => (int) ($c[2] ?? 0),
            // Transactions, not statements: PostgreSQL does not count
            // statements globally, and a made-up number would be worse than
            // the nearest true one.
            'queries' => (int) ($c[3] ?? 0),
            // Null, not zero. PostgreSQL has no slow-query counter without
            // `pg_stat_statements`, an extension the panel does not install —
            // so there is nothing to report, and a screen rendering `0`
            // presents "we never looked" as "none happened", which is the
            // better news of the two and the false one. MongoEngine already
            // answers null here for the same reason; zero was my
            // inconsistency, reported by the frontend on the day it shipped.
            'slow_queries' => null,
            'uptime_seconds' => (int) ($c[4] ?? 0),
        ];
    }

    public function tables(string $database): array
    {
        // Runs inside the database being inspected: PostgreSQL's catalogs are
        // per-database, unlike MySQL's information_schema which spans the
        // server. `n_live_tup` is an estimate for the same reason MySQL's
        // `table_rows` is — counting every row of every table to draw a list
        // would be a table scan per row on the screen.
        $result = $this->runIn($database,
            'SELECT relname, COALESCE(n_live_tup, 0), COALESCE(pg_total_relation_size(relid), 0) '
            .'FROM pg_stat_user_tables ORDER BY relname;'
        );

        if ($result->failed()) {
            return [];
        }

        $tables = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $c = explode("\t", $line);
            $tables[] = ['name' => $c[0] ?? '', 'rows' => (int) ($c[1] ?? 0), 'size_bytes' => (int) ($c[2] ?? 0)];
        }

        return $tables;
    }

    /**
     * Plain SQL, not a custom-format archive.
     *
     * `--format=plain` keeps the file a `.sql` the backup and restore steps
     * already know how to name, size and sanity-check, and it is restorable
     * with `psql` alone — so a user with a dump and no panel is not stuck.
     *
     * `--no-owner` and `--no-privileges` because ownership is the panel's to
     * decide: {@see restore()} runs as the database's owning role, and a dump
     * carrying `ALTER TABLE … OWNER TO` would reinstate whoever owned it on the
     * machine it came from — a role that need not exist here.
     */
    public function dump(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->engine()}.dump_client", 'pg_dump');

        $this->mustRun([
            $client,
            ...$this->connectionArgs(),
            '--dbname='.$database,
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--file='.$path,
        ], 'export', 600);
    }

    /**
     * Import a plain dump. Destructive — the caller drops and recreates the
     * database first, so this is the inverse of dump() rather than a merge.
     *
     * `--file`, not stdin: a site's dump is routinely larger than the whole PHP
     * memory limit, and reading it in to pass as process input would kill the
     * worker on exactly the databases most worth restoring.
     *
     * `ON_ERROR_STOP=1` matters more here than anywhere else. Without it psql
     * replays a dump that fails halfway and still exits 0 — a restore that
     * reports success over a half-populated database, discovered later.
     */
    public function restore(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->engine()}.client", 'psql');

        $this->mustRun([
            $client,
            ...$this->connectionArgs(),
            '--dbname='.$database,
            '--set=ON_ERROR_STOP=1',
            '--file='.$path,
        ], 'restore', 3600);
    }

    private function must(string $sql): void
    {
        $result = $this->run($sql);

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    private function mustIn(string $database, string $sql): void
    {
        $result = $this->runIn($database, $sql);

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    private function run(string $sql): ServerOpsResult
    {
        return $this->runIn(self::MAINTENANCE_DATABASE, $sql);
    }

    /**
     * Run SQL against one database.
     *
     * The flags are not interchangeable with a tidier set:
     *
     *  - `--set=ON_ERROR_STOP=1` is what makes a failure a failure. Measured on
     *    PostgreSQL 16: SQL read from stdin that errors exits **0** without it,
     *    and 3 with it. Every `must()` above depends on this one flag.
     *  - `-t` drops the header and row count, `-A` turns off column alignment
     *    and `-F` sets the separator — together they give exactly the
     *    tab-separated, header-free output the parsers above expect.
     *  - `-q` silences `CREATE ROLE`-style command tags, so `output()` holds
     *    query results and nothing else.
     *
     * SQL goes over stdin and the password lives in a 0600 PGPASSFILE, so
     * neither reaches argv, where anything is readable in /proc for the life of
     * the process.
     */
    private function runIn(string $database, string $sql): ServerOpsResult
    {
        $client = (string) config("server.databases.engines.{$this->engine()}.client", 'psql');
        $passFile = $this->writePassFile();

        try {
            return $this->serverOps->run(
                [
                    $client,
                    ...$this->connectionArgs(),
                    '--dbname='.$database,
                    '--set=ON_ERROR_STOP=1',
                    '-t', '-A', '-F', "\t", '-q',
                ],
                ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'query'],
                60,
                $sql,
                env: ['PGPASSFILE' => $passFile],
            );
        } finally {
            @unlink($passFile);
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    private function mustRun(array $command, string $op, int $timeout): void
    {
        $passFile = $this->writePassFile();

        try {
            $result = $this->serverOps->run(
                $command,
                ['feature' => 'database', 'engine' => $this->engine(), 'op' => $op],
                $timeout,
                env: ['PGPASSFILE' => $passFile],
            );

            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            @unlink($passFile);
        }
    }

    /**
     * Host, port and user — never the password, which travels in PGPASSFILE.
     *
     * `--no-password` so a client that cannot authenticate fails immediately
     * instead of blocking on a prompt that nothing will ever answer. A
     * background job hanging on an invisible password prompt is indefinite,
     * and looks like the queue has stalled rather than like a bad credential.
     *
     * @return array<int, string>
     */
    private function connectionArgs(): array
    {
        return [
            '--host='.($this->connection->connection_type === 'socket' && $this->connection->socket
                ? (string) $this->connection->socket
                : (string) ($this->connection->host ?: '127.0.0.1')),
            '--port='.(int) ($this->connection->port ?: 5432),
            '--username='.(string) $this->connection->username,
            '--no-password',
        ];
    }

    /**
     * A 0600 pgpass file: `host:port:database:user:password`.
     *
     * The mode is not defensive tidiness — measured on PostgreSQL 16, a pgpass
     * file with group or world access is refused with a warning and the
     * password is simply not used, so a 0644 file authenticates nothing.
     *
     * `*` for the database field because these credentials are the admin
     * connection's and are used against the maintenance database, each managed
     * database in turn, and pg_dump.
     */
    private function writePassFile(): string
    {
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $file = $dir.'/pg-'.bin2hex(random_bytes(8)).'.pgpass';

        $host = $this->connection->connection_type === 'socket' && $this->connection->socket
            ? (string) $this->connection->socket
            : (string) ($this->connection->host ?: '127.0.0.1');

        // A literal colon or backslash in a field has to be escaped or it is
        // read as a separator — which would silently shift every field after
        // it and authenticate as somebody else, or nobody.
        $fields = array_map(
            fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value),
            [$host, (string) ((int) ($this->connection->port ?: 5432)), '*', (string) $this->connection->username, (string) $this->connection->password],
        );

        file_put_contents($file, implode(':', $fields)."\n");
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Double-quote an identifier (already regex-validated upstream).
     *
     * Quoting is not optional here the way back-ticking is in MySQL: an
     * unquoted PostgreSQL identifier is folded to lower case, so a database
     * created as `Shop` would be created as `shop` and every later statement
     * naming `Shop` would miss it.
     */
    private function ident(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * A single-quoted string literal, quotes included.
     *
     * Returns the delimiters as well as the content, unlike the MySQL engine's
     * `esc()`, so no caller can interpolate a value and forget to quote it.
     */
    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
