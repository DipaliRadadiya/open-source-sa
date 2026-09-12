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
 *     `'user'@'host'` — creating the account *is* the grant. Here the host half
 *     lives in `pg_hba.conf`, and binding lives in `listen_addresses`, so one
 *     MySQL statement becomes three facts in three places that must agree.
 *
 *     Until 2026-09-12 every `$host` here was accepted and ignored, and the
 *     request layer refused to offer remote access at all rather than store a
 *     setting nothing applied. It is now implemented: see the remote-access
 *     section below. `$host` is no longer decorative, and the only argument
 *     still ignored is `$newHost`'s counterpart in {@see setPassword()}, where
 *     a password change genuinely says nothing about addresses.
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

    /**
     * Drop the database, then the roles — with **no** `REASSIGN OWNED` /
     * `DROP OWNED`, which is the one thing that separates this from calling
     * `dropUser()` in a loop.
     *
     * Those two statements have to run *inside* the database whose objects are
     * owned, and by this point that database no longer exists: psql cannot
     * connect, so the cleanup that was meant to make `DROP ROLE` possible is
     * what fails instead. Skipping it is not a workaround. `WITH (FORCE)`
     * above takes the owned objects with the database, so the role that owned
     * them owns nothing by the time it is dropped — `DatabaseUser` is scoped
     * to exactly one database, so there is nowhere else for it to own
     * anything the panel gave it.
     *
     * @param  array<int, array{username: string, host: string}>  $users
     */
    public function teardownDatabase(string $name, array $users): void
    {
        $this->dropDatabase($name);

        foreach ($users as $user) {
            $this->must('DROP ROLE IF EXISTS '.$this->ident($user['username']).';');
        }
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

        // The host half of the account. A role is cluster-wide, so this is the
        // only place the address is recorded — and without it a user created
        // as "remote" would be a role that exists and cannot connect.
        $this->syncHbaRule($database, $username, $host);
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

        // Before the role goes, while its name still means something. A `host`
        // line naming a dropped role is a grant nobody can see and nothing
        // reports.
        $this->syncHbaRule($database, $username, null);

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

        // The rule is keyed by role name, so a rename has to move it: left
        // alone, the old name keeps a grant and the new one has none.
        $this->syncHbaRule($database, $username, null);
        $this->syncHbaRule($database, $newUsername, $newHost);
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

    /*
    |---------------------------------------------------------------------------
    | Remote access — pg_hba.conf and listen_addresses
    |---------------------------------------------------------------------------
    |
    | A role is cluster-wide and carries no host, so "which addresses may reach
    | this account" is two separate facts held in two separate places, and both
    | have to be true:
    |
    |   * `pg_hba.conf` decides which client addresses may authenticate. Read on
    |     start-up and on SIGHUP, so a change takes a reload.
    |   * `listen_addresses` decides which interfaces are bound at all. Its
    |     default is `localhost`, and PostgreSQL's documentation is explicit:
    |     "This parameter can only be set at server start." No reload will do —
    |     it takes a **restart**, which interrupts every application connected
    |     to the cluster. That is why granting remote access cannot be a silent
    |     side effect of creating a user.
    */

    /**
     * Where this cluster's `pg_hba.conf` actually is.
     *
     * Asked, never assembled from a version and a cluster name. The path is
     * `/etc/postgresql/<version>/<cluster>/pg_hba.conf` on Ubuntu and something
     * else everywhere else, and `hba_file` is the server's own answer — the
     * same reasoning that made the installer discover the cluster with
     * `pg_lsclusters` rather than assume `16-main`.
     */
    private function hbaPath(): ?string
    {
        $result = $this->run('SHOW hba_file;');

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    /**
     * Grant one role remote access to one database, or take it away.
     *
     * `$host` is the panel's stored preference: `localhost` (no rule at all),
     * `%` (anywhere), or an address/CIDR. Null removes the account's rules,
     * which is what a drop or a move back to localhost-only means.
     *
     * The whole block is re-rendered from what is already in the file plus this
     * one change, so the account's previous rules are replaced rather than
     * accumulated — a user moved from one address to another must not keep the
     * old one.
     */
    private function syncHbaRule(string $database, string $role, ?string $host): void
    {
        $path = $this->hbaPath();

        if ($path === null) {
            throw new DatabaseOperationException($this->run('SHOW hba_file;')->reference);
        }

        $contents = $this->readFile($path);
        $rules = PgHbaFile::rules($contents);
        $key = PgHbaFile::key($database, $role);

        if ($host === null || $host === 'localhost') {
            unset($rules[$key]);
        } else {
            $rules[$key] = PgHbaFile::lines($database, $role, $host);
        }

        $rendered = PgHbaFile::render($contents, $rules);

        if ($rendered === $contents) {
            return;
        }

        $this->writeHba($path, $contents, $rendered);
    }

    /**
     * Write, prove it parses, then reload — and put the old file back if it
     * does not.
     *
     * `pg_hba_file_rules` is what makes this safe rather than hopeful:
     * PostgreSQL's documentation says it "reports on the current contents of
     * the file, not on what was last loaded by the server", and recommends it
     * "for pre-testing changes". So the file on disk can be checked *before*
     * anything is signalled, and a reload only ever happens against a file
     * already known to be valid.
     *
     * The previous contents are restored on any failure. Not a copy left beside
     * it under another name: a stale `pg_hba.conf.bak` next to a live one is a
     * trap for the next person to read the directory.
     */
    private function writeHba(string $path, string $previous, string $rendered): void
    {
        $this->writeFile($path, $rendered);

        $check = $this->run('SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL;');

        // A cluster too old for the view, or one that would not answer, is not
        // a licence to reload an unverified authentication file.
        if ($check->failed() || (int) trim($check->output()) !== 0) {
            $this->writeFile($path, $previous);

            throw new DatabaseOperationException($check->reference);
        }

        $this->must('SELECT pg_reload_conf();');
    }

    /**
     * `tee`, not a shell redirect.
     *
     * `pg_hba.conf` is `0640 postgres:postgres`. `tee` writes through the
     * existing inode and leaves owner and mode exactly as they were; a redirect
     * run as root would create a root-owned file, which the postmaster refuses
     * to read — turning an authentication change into a cluster that cannot
     * authenticate anyone.
     */
    private function writeFile(string $path, string $contents): void
    {
        $result = $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'hba_write'],
            30,
            $contents,
        );

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    private function readFile(string $path): string
    {
        $result = $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'hba_read'],
        );

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return $result->output();
    }

    /**
     * Which interfaces the cluster is bound to, as the running server sees it.
     *
     * `localhost` (the default) means no remote client can connect whatever
     * `pg_hba.conf` says, so this is what decides whether a restart is needed.
     */
    public function listenAddresses(): string
    {
        $result = $this->run('SHOW listen_addresses;');

        return $result->ok ? trim($result->output()) : 'localhost';
    }

    /**
     * Is the cluster already reachable from off the box?
     *
     * Anything that is not the default loopback-only setting counts: an
     * operator who has already set `listen_addresses` to a specific interface
     * has made this decision themselves, and the panel has no business
     * restarting their cluster to widen it further.
     */
    public function listensRemotely(): bool
    {
        $value = $this->listenAddresses();

        return $value !== '' && $value !== 'localhost' && $value !== '127.0.0.1' && $value !== '::1';
    }

    /**
     * Bind every interface, then restart — the only way this setting takes.
     *
     * Written with `ALTER SYSTEM`, which lands in `postgresql.auto.conf` rather
     * than editing `postgresql.conf`: the operator's own file stays theirs, and
     * the override is visible in one obvious place and removable with
     * `ALTER SYSTEM RESET`.
     *
     * Binding is not granting. A cluster listening on every interface still
     * authenticates nobody without a matching `pg_hba.conf` record, and the
     * firewall still has to open 5432 — this is the first of three locks, not
     * the only one.
     */
    public function openRemoteListening(): void
    {
        $this->must("ALTER SYSTEM SET listen_addresses = '*';");

        $cluster = $this->cluster();

        if ($cluster === null) {
            throw new DatabaseOperationException(null);
        }

        $result = $this->serverOps->run(
            ['systemctl', 'restart', "postgresql@{$cluster}"],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'restart_cluster'],
            120,
        );

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    /**
     * The cluster as `<version>-<name>`, discovered rather than assumed.
     *
     * Same source the installer uses. A server whose cluster is not `16-main`
     * is not exotic — a second cluster on a different port is ordinary — and
     * restarting the wrong unit would report success having done nothing.
     */
    private function cluster(): ?string
    {
        $result = $this->serverOps->run(
            ['pg_lsclusters', '--no-header'],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'cluster'],
        );

        if ($result->failed()) {
            return null;
        }

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) >= 2 && $fields[0] !== '') {
                return $fields[0].'-'.$fields[1];
            }
        }

        return null;
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
