<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Contracts\ListensRemotely;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\Server\Databases\Imports\PgsqlLexer;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PostgreSQL via `psql`. A separate driver from {@see SqlEngine}, not a subclass of it.
 *
 * Differences from MySQL that a literal translation would turn into silent failures:
 *  1. `psql` exits 0 after a failed statement read from stdin, so every {@see must()} needs
 *     `ON_ERROR_STOP=1`.
 *  2. `GRANT ALL PRIVILEGES ON DATABASE` grants no table privileges, and since PG15 `public`
 *     is not writable by PUBLIC. The model is ownership: the role owns the database and schema.
 *  3. `DROP DATABASE` fails while sessions are connected and `DROP ROLE` while the role owns
 *     anything: hence `WITH (FORCE)` and `DROP OWNED BY`.
 *  4. A role is cluster-wide with no host: the host half lives in `pg_hba.conf`, the binding
 *     in `listen_addresses` (see the remote-access section below).
 *
 * Credentials go in a 0600 `PGPASSFILE`, never on argv. Identifiers are double-quoted and
 * string literals single-quoted and escaped; names are validated upstream by FormRequests.
 */
class PgsqlEngine implements DatabaseEngine, ListensRemotely
{
    /** Maintenance database: `CREATE DATABASE` cannot run from inside the database it creates. */
    private const MAINTENANCE_DATABASE = 'postgres';

    /** What `env` exits with when the client program is not installed. */
    private const CLIENT_MISSING = 127;

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
        return $this->run('SELECT 1;', probe: true)->ok;
    }

    public function version(): ?string
    {
        // `server_version`, not `version()`: that one is a sentence with compiler and platform.
        $result = $this->run('SHOW server_version;', probe: true);

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    public function listDatabases(): array
    {
        // `datallowconn` excludes template0, which cannot be connected to.
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
        // `rolcanlogin`: a role that cannot log in is a group, not an account.
        // Not `rolsuper`: `postgres` and the panel's own `panel_*` account must never be offered
        // as site users, or they could be renamed or dropped from the Databases screen.
        $result = $this->run(
            'SELECT rolname FROM pg_roles WHERE rolcanlogin = true AND rolsuper = false ORDER BY rolname;'
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
                // A role has no host; reported as localhost so the shape matches the other engines.
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

        // Strict preflight: an unreachable server must throw, not look empty and approve a
        // taken name.
        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === '1';
    }

    public function userExists(string $username, string $host, string $database): bool
    {
        // A role is cluster-wide: the host is not part of it.
        $result = $this->run(sprintf(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname = %s) THEN 'user_exists' ELSE 'user_free' END;",
            $this->literal($username),
        ));

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === 'user_exists';
    }

    /**
     * The databases an account owns or was granted by name. Not "can connect to": CONNECT is
     * granted to PUBLIC, which would match every database.
     *
     * @return array<int, string>
     */
    private function reachableDatabases(string $username): array
    {
        $result = $this->run(sprintf(
            'SELECT d.datname FROM pg_database d '
            .'WHERE d.datistemplate = false AND d.datallowconn = true '
            .'AND (d.datdba = (SELECT oid FROM pg_roles WHERE rolname = %1$s) '
            .'OR EXISTS (SELECT 1 FROM aclexplode(d.datacl) a '
            .'WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = %1$s))) '
            .'ORDER BY d.datname;',
            $this->literal($username),
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
     * `$charset` is ENCODING, `$collation` is LC_COLLATE. `TEMPLATE template0` whenever either
     * is given: only template0 may be copied with a different encoding or collation.
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

        // PostgreSQL grants CONNECT and TEMP to PUBLIC on every new database; revoke them so a
        // site's users cannot open other sites' databases. createUser() grants its users by name.
        $this->must($sql.'; REVOKE CONNECT, TEMPORARY ON DATABASE '.$this->ident($name).' FROM PUBLIC;');
    }

    /**
     * `WITH (FORCE)` (PostgreSQL 13+): a plain DROP fails while a session is connected.
     * `IF EXISTS` so a retry can finish a deletion that failed while cleaning up the role.
     */
    public function dropDatabase(string $name): void
    {
        $this->must('DROP DATABASE IF EXISTS '.$this->ident($name).' WITH (FORCE);');
    }

    /**
     * Take CONNECT and TEMP away from PUBLIC on an existing database, as {@see createDatabase()}
     * does for a new one.
     *
     * Every role that uses the database (owners and grantees in it, plus the panel's recorded
     * users) first gets CONNECT by name, so nobody is locked out. One transaction, grants first.
     *
     * @param  array<int, string>  $users  The panel's recorded users of it.
     * @return bool Whether anything changed. False when PUBLIC already had no
     *              CONNECT, or the database does not exist.
     */
    public function restrictToItsUsers(string $database, array $users): bool
    {
        $publicCanConnect = trim($this->run(sprintf(
            "SELECT datacl IS NULL OR EXISTS (SELECT 1 FROM aclexplode(datacl) a WHERE a.grantee = 0 AND a.privilege_type = 'CONNECT') FROM pg_database WHERE datname = %s;",
            $this->literal($database),
        ))->output());

        if ($publicCanConnect !== 't') {
            return false;
        }

        $named = $users === [] ? 'NULL' : implode(', ', array_map(fn (string $user) => $this->literal($user), $users));

        $result = $this->runIn($database, <<<SQL
            SELECT r.rolname FROM pg_roles r
            WHERE NOT r.rolsuper AND r.rolname NOT LIKE 'pg\_%' AND (
                r.rolname IN ({$named})
                OR EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspowner = r.oid AND n.nspname NOT LIKE 'pg\_%' AND n.nspname <> 'information_schema')
                OR EXISTS (SELECT 1 FROM pg_class c WHERE c.relowner = r.oid AND c.relnamespace NOT IN (SELECT oid FROM pg_namespace WHERE nspname LIKE 'pg\_%' OR nspname = 'information_schema'))
                OR EXISTS (SELECT 1 FROM pg_namespace n, aclexplode(n.nspacl) a WHERE a.grantee = r.oid AND n.nspname NOT LIKE 'pg\_%' AND n.nspname <> 'information_schema')
                OR EXISTS (SELECT 1 FROM pg_class c, aclexplode(c.relacl) a WHERE a.grantee = r.oid AND c.relnamespace NOT IN (SELECT oid FROM pg_namespace WHERE nspname LIKE 'pg\_%' OR nspname = 'information_schema'))
            );
            SQL);

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        $roles = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));

        $grant = $roles === [] ? '' : sprintf(
            'GRANT CONNECT, TEMPORARY ON DATABASE %s TO %s; ',
            $this->ident($database),
            implode(', ', array_map(fn (string $role) => $this->ident($role), $roles)),
        );

        $this->must(sprintf(
            'BEGIN; %sREVOKE CONNECT, TEMPORARY ON DATABASE %s FROM PUBLIC; COMMIT;',
            $grant,
            $this->ident($database),
        ));

        return true;
    }

    /**
     * Drop the database, then the roles. No `REASSIGN OWNED` / `DROP OWNED`: those must run
     * inside the database, which is gone; `WITH (FORCE)` takes the owned objects with it.
     *
     * @param  array<int, array{username: string, host: string}>  $users
     */
    public function teardownDatabase(string $name, array $users): void
    {
        $this->dropDatabase($name);

        foreach ($users as $user) {
            $this->must('DROP ROLE IF EXISTS '.$this->ident($user['username']).';');
        }

        // Its owner group, if it had one.
        $this->must('DROP ROLE IF EXISTS '.$this->ident($this->ownerGroup($name)).';');
    }

    public function databaseSize(string $name): ?int
    {
        $result = $this->run(sprintf(
            'SELECT COALESCE(pg_database_size(%s), 0);',
            $this->literal($name),
        ));

        $output = trim($result->output());

        return $result->ok && is_numeric($output) ? (int) $output : null;
    }

    /**
     * `encoding` (an oid, converted by `pg_encoding_to_char()`) and `datcollate` (LC_COLLATE).
     *
     * @return array{charset: ?string, collation: ?string}
     */
    public function describeDatabase(string $name): array
    {
        $result = $this->run(sprintf(
            'SELECT pg_encoding_to_char(encoding), datcollate FROM pg_database WHERE datname = %s;',
            $this->literal($name),
        ));

        if (! $result->ok) {
            return ['charset' => null, 'collation' => null];
        }

        // `-A -F'\t'` above: unaligned, tab-separated, no header.
        $columns = preg_split('/\t/', trim($result->output())) ?: [];

        return [
            'charset' => ($columns[0] ?? '') !== '' ? $columns[0] : null,
            'collation' => ($columns[1] ?? '') !== '' ? $columns[1] : null,
        ];
    }

    /**
     * Create the role and give it its database.
     *
     * Ownership, not `GRANT ALL PRIVILEGES ON DATABASE`, which leaves the account without table
     * privileges. The first user owns the database itself; a database has one owner, so from
     * the second user on it belongs to a shared role ({@see shareWithGroup()}). The schema is
     * re-owned in a second connection because `ALTER SCHEMA` must run inside that database.
     *
     * `$host` only feeds the `pg_hba.conf` rule: a role itself is cluster-wide.
     */
    public function createUser(string $username, string $host, string $password, string $database): void
    {
        $this->must(sprintf(
            'CREATE ROLE %s WITH LOGIN PASSWORD %s;',
            $this->ident($username),
            $this->literal($password),
        ));

        [$owner, $kind] = $this->databaseOwner($database);
        $group = $this->ownerGroup($database);

        if ($owner === $group) {
            $this->joinGroup($username, $database, $group);
        } elseif ($kind === 'login' && $owner !== $username) {
            $this->shareWithGroup($database, $owner, $username, $group);
        } else {
            $this->must(sprintf('ALTER DATABASE %s OWNER TO %s;', $this->ident($database), $this->ident($username)));

            $this->mustIn($database, sprintf(
                'ALTER SCHEMA public OWNER TO %s; GRANT ALL ON SCHEMA public TO %s;',
                $this->ident($username),
                $this->ident($username),
            ));
        }

        // A role is cluster-wide: the hba rule is the only place the address is recorded.
        $this->syncHbaRule($database, $username, $host);
    }

    /**
     * The shared role that owns a database once it has more than one user. `#` cannot appear in
     * a username. Names are cut at 63 bytes silently, so a long one is shortened with a hash of
     * the whole name: two databases sharing a long prefix must not share an owner.
     */
    public function ownerGroup(string $database): string
    {
        $suffix = '#owner';

        if (strlen($database.$suffix) <= 63) {
            return $database.$suffix;
        }

        return substr($database, 0, 63 - strlen($suffix) - 9).'-'.substr(sha1($database), 0, 8).$suffix;
    }

    /**
     * Who owns the database, and whether that is a site's login role: 'login' (ordinary
     * account), 'nologin' (the group) or 'super' (e.g. the panel's connection account). Only a
     * 'login' owner is ever moved into a group.
     *
     * @return array{0: string, 1: string}
     */
    private function databaseOwner(string $database): array
    {
        $row = explode("\t", trim($this->run(sprintf(
            "SELECT r.rolname, CASE WHEN r.rolsuper THEN 'super' WHEN r.rolcanlogin THEN 'login' ELSE 'nologin' END "
            .'FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba WHERE d.datname = %s;',
            $this->literal($database),
        ))->output()));

        return [$row[0], $row[1] ?? 'super'];
    }

    /**
     * A second user: everything the first user owns moves to a shared role nobody logs in as,
     * and both become members. Each acts as that role in this database (`SET role`), so tables
     * either creates belong to the group. Otherwise the second user took over the schema and
     * the first could no longer create tables.
     */
    private function shareWithGroup(string $database, string $owner, string $username, string $group): void
    {
        $exists = trim($this->run(sprintf('SELECT 1 FROM pg_roles WHERE rolname = %s;', $this->literal($group)))->output()) === '1';

        // One transaction: an application connecting halfway would meet tables owned by a role
        // it is not yet acting as.
        $this->mustIn($database, 'BEGIN; '
            .($exists ? '' : sprintf('CREATE ROLE %s NOLOGIN; ', $this->ident($group)))
            .$this->reassignWithin($database, $owner, $group)
            .$this->joinGroupSql($owner, $database, $group)
            .$this->joinGroupSql($username, $database, $group)
            .'COMMIT;');
    }

    private function joinGroup(string $username, string $database, string $group): void
    {
        $this->must($this->joinGroupSql($username, $database, $group));
    }

    /**
     * Make a user a member of the database's group, acting as it there. CONNECT by name too:
     * {@see reachableDatabases()} and {@see restrictToItsUsers()} recognise users by it.
     */
    private function joinGroupSql(string $username, string $database, string $group): string
    {
        return sprintf(
            'GRANT %1$s TO %2$s; GRANT CONNECT, TEMPORARY ON DATABASE %3$s TO %2$s; ALTER ROLE %2$s IN DATABASE %3$s SET role = %1$s; ',
            $this->ident($group),
            $this->ident($username),
            $this->ident($database),
        );
    }

    /**
     * `REASSIGN OWNED`, kept to one database: it also moves every other database the role owns,
     * so those are handed straight back. Returned as SQL for the caller's transaction.
     */
    private function reassignWithin(string $database, string $from, string $to): string
    {
        $others = array_values(array_filter(array_map('trim', explode("\n", $this->run(sprintf(
            'SELECT datname FROM pg_database WHERE datdba = (SELECT oid FROM pg_roles WHERE rolname = %s) AND datname <> %s;',
            $this->literal($from),
            $this->literal($database),
        ))->output()))));

        return sprintf('REASSIGN OWNED BY %s TO %s; ', $this->ident($from), $this->ident($to))
            .implode('', array_map(
                fn (string $other) => sprintf('ALTER DATABASE %s OWNER TO %s; ', $this->ident($other), $this->ident($from)),
                $others,
            ));
    }

    /**
     * `DROP OWNED BY` first: `DROP ROLE` is refused while the role owns anything. It runs inside
     * the database, so over a separate connection. The database goes back to the connection's
     * own account rather than being dropped: removing a user must not delete a site's data.
     */
    public function dropUser(string $username, string $host, string $database): void
    {
        // Into the group when the database has one: what this user made is the others' data too.
        $group = $this->ownerGroup($database);
        $admin = $this->databaseOwner($database)[0] === $group ? $group : (string) $this->connection->username;

        // Reassign before dropping so the database survives its owner. Sent together: a REASSIGN
        // that succeeds and a DROP OWNED that does not would leave the role undroppable.
        $this->mustIn($database, sprintf(
            'REASSIGN OWNED BY %s TO %s; DROP OWNED BY %s;',
            $this->ident($username),
            $this->ident($admin),
            $this->ident($username),
        ));

        // Before the role goes, while its name still means something: a `host` line naming a
        // dropped role is a grant nobody can see.
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
     * Rename in place. The password survives a rename under scram-sha-256 (the default), so
     * `$password` is unused and the driver reports `rename_keeps_password`. (Under the deprecated
     * md5 encoding a rename clears it, because the username is part of the hash.)
     */
    public function renameUser(string $username, string $host, string $newUsername, string $newHost, string $password, string $database): void
    {
        $this->must(sprintf(
            'ALTER ROLE %s RENAME TO %s;',
            $this->ident($username),
            $this->ident($newUsername),
        ));

        // The hba rule is keyed by role name: move it, or the old name keeps a grant and the new
        // one has none.
        $this->syncHbaRule($database, $username, null);
        $this->syncHbaRule($database, $newUsername, $newHost);
    }

    /** Live backends, excluding this connection so the panel is not always in its own list. */
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

    /** `pg_terminate_backend`, not `pg_cancel_backend`: cancel leaves the connection open. */
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
            // Transactions, not statements: PostgreSQL has no global statement count.
            'queries' => (int) ($c[3] ?? 0),
            // Null, not zero: there is no slow-query counter without `pg_stat_statements`, which
            // the panel does not install, and `0` would present "never looked" as "none happened".
            'slow_queries' => null,
            'uptime_seconds' => (int) ($c[4] ?? 0),
        ];
    }

    public function tables(string $database): array
    {
        // Runs inside the database being inspected (catalogs are per-database). `n_live_tup` is
        // an estimate: counting every table's rows to draw a list would be a scan per table.
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
     * Plain SQL, restorable with `psql` alone. `--no-owner` and `--no-privileges`: ownership is
     * the panel's to decide ({@see restore()}), and a dump carrying `OWNER TO` would reinstate a
     * role that need not exist here.
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
     * Import a plain dump. Destructive: the caller drops and recreates the database first.
     *
     * Runs as the database's owner (`SET ROLE`), not the panel's account: the dump carries no
     * ownership, so whoever loads it owns every table. The caller gives the database back to its
     * users first ({@see reattachUsers()}); a database with no user loads as the panel.
     *
     * Also as the panel when the dump creates an extension only a superuser may create
     * (PostGIS): its `COMMENT ON EXTENSION` needs the extension's owner, so the load would fail.
     *
     * `--file`, not stdin: a dump can be larger than PHP's memory limit. `ON_ERROR_STOP=1`:
     * without it psql replays a failing dump to the end and exits 0.
     */
    public function restore(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->engine()}.client", 'psql');

        [$owner, $kind] = $this->databaseOwner($database);
        $asOwner = $owner !== '' && $kind !== 'super' && $this->ownerMayCreate($database, $this->extensionsIn($path));

        $this->mustRun([
            $client,
            ...$this->connectionArgs(),
            '--dbname='.$database,
            '--set=ON_ERROR_STOP=1',
            // psql runs -c and -f in order, in one session.
            ...($asOwner ? ['--command=SET ROLE '.$this->ident($owner).';'] : []),
            '--file='.$path,
        ], 'restore', 3600);
    }

    /**
     * Give a recreated database back to its users ({@see DatabaseEngine}): one user owns it and
     * its schema; several share it through the group ({@see shareWithGroup()}), created if
     * missing. One transaction, inside the database.
     */
    /**
     * Load a file as the database's own user, over TCP (a site user authenticates with its
     * password there, not by the socket's peer rule), so their `SET role = {db}#owner` applies
     * and the file's objects belong to the owner group.
     *
     * One transaction: a failing file leaves nothing behind. psql's own client commands are
     * refused before this runs ({@see PgsqlLexer}): psql has no switch to disable them, and
     * this client runs as root.
     */
    public function importAs(string $database, string $path, string $username, string $password, bool $gzip = false): void
    {
        $account = $this->connection->replicate();
        $account->username = $username;
        $account->password = $password;

        if ($account->connection_type === 'socket') {
            $account->connection_type = 'tcp';
            $account->host = '127.0.0.1';
        }

        (new self($account, $this->serverOps))->load($database, $path);
    }

    private function load(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->engine()}.client", 'psql');

        $this->mustRun([
            $client,
            ...$this->connectionArgs(),
            '--dbname='.$database,
            '--no-psqlrc',
            '--set=ON_ERROR_STOP=1',
            '--single-transaction',
            '--quiet',
            '--file='.$path,
        ], 'import', (int) config('server.databases.import_timeout', 7200));
    }

    /**
     * Turn a `pg_dump -Fc` archive into plain SQL so it goes through the same checks as any
     * other file (`pg_restore --file` does not connect). Owner and privilege lines are left out.
     */
    public function customToPlain(string $archive, string $target): void
    {
        $client = (string) config("server.databases.engines.{$this->engine()}.restore_client", 'pg_restore');

        $result = $this->serverOps->run(
            [$client, '--no-owner', '--no-privileges', '--file='.$target, $archive],
            ['feature' => 'database', 'engine' => $this->engine(), 'op' => 'import.convert'],
            (int) config('server.databases.import_timeout', 7200),
        );

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    public function reattachUsers(string $database, array $usernames): void
    {
        // Only roles that still exist: one dropped outside the panel would fail the whole
        // transaction after the database was already dropped.
        $usernames = $usernames === [] ? [] : array_values(array_filter(array_map('trim', explode("\n", $this->run(sprintf(
            'SELECT rolname FROM pg_roles WHERE rolname IN (%s) ORDER BY rolname;',
            implode(', ', array_map(fn (string $user) => $this->literal($user), array_unique($usernames))),
        ))->output()))));

        if ($usernames === []) {
            return;
        }

        if (count($usernames) === 1) {
            $user = $this->ident($usernames[0]);

            $this->mustIn($database, sprintf(
                'BEGIN; ALTER DATABASE %1$s OWNER TO %2$s; ALTER SCHEMA public OWNER TO %2$s; GRANT ALL ON SCHEMA public TO %2$s; COMMIT;',
                $this->ident($database),
                $user,
            ));

            return;
        }

        $group = $this->ownerGroup($database);
        $exists = trim($this->run(sprintf('SELECT 1 FROM pg_roles WHERE rolname = %s;', $this->literal($group)))->output()) === '1';

        $this->mustIn($database, 'BEGIN; '
            .($exists ? '' : sprintf('CREATE ROLE %s NOLOGIN; ', $this->ident($group)))
            .sprintf('ALTER DATABASE %1$s OWNER TO %2$s; ALTER SCHEMA public OWNER TO %2$s; ', $this->ident($database), $this->ident($group))
            .implode('', array_map(fn (string $user) => $this->joinGroupSql($user, $database, $group), $usernames))
            .'COMMIT;');
    }

    /**
     * The extensions a dump creates, read a line at a time (dumps can exceed PHP's memory limit).
     *
     * @return array<int, string>
     */
    private function extensionsIn(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $names = [];

        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^CREATE EXTENSION (?:IF NOT EXISTS )?("(?:[^"]|"")+"|[A-Za-z0-9_-]+)/', $line, $m) === 1) {
                    $names[] = str_replace('""', '"', trim($m[1], '"'));
                }
            }
        } finally {
            fclose($handle);
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether every one of these extensions may be created by a database's owner: marked
     * trusted in its control file (PostgreSQL 13+). An unknown one counts as not.
     *
     * @param  array<int, string>  $extensions
     */
    private function ownerMayCreate(string $database, array $extensions): bool
    {
        if ($extensions === []) {
            return true;
        }

        $trusted = array_filter(array_map('trim', explode("\n", $this->runIn($database, sprintf(
            'SELECT DISTINCT name FROM pg_available_extension_versions WHERE trusted AND name IN (%s);',
            implode(', ', array_map(fn (string $name) => $this->literal($name), $extensions)),
        ))->output())));

        return array_diff($extensions, $trusted) === [];
    }

    /*
    |---------------------------------------------------------------------------
    | Remote access — pg_hba.conf and listen_addresses
    |---------------------------------------------------------------------------
    |
    | Two facts in two places, both must hold: `pg_hba.conf` says which client addresses may
    | authenticate (a reload applies it); `listen_addresses` says which interfaces are bound
    | and only takes a **restart**, so granting remote access is never a silent side effect.
    */

    /**
     * Where `pg_hba.conf` is: asked of the server, never assembled from a version and cluster name.
     */
    private function hbaPath(): ?string
    {
        $result = $this->run('SHOW hba_file;');

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    /**
     * Grant one role remote access to one database, or take it away.
     *
     * `$host` is `localhost` (no rule), `%` (anywhere) or an address/CIDR; null removes the
     * account's rules. The block is re-rendered from the file's current rules plus this change,
     * so a user moved to another address does not keep the old one.
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
     * Write, prove it parses, then reload, and put the old file back if it does not.
     * `pg_hba_file_rules` reports on the file's current contents, so it can be checked before
     * anything is signalled. The old contents are restored in place, not left in a `.bak`.
     */
    private function writeHba(string $path, string $previous, string $rendered): void
    {
        $this->writeFile($path, $rendered);

        $check = $this->run('SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL;');

        // Too old for the view, or no answer: never reload an unverified authentication file.
        if ($check->failed() || (int) trim($check->output()) !== 0) {
            $this->writeFile($path, $previous);

            throw new DatabaseOperationException($check->reference);
        }

        $this->must('SELECT pg_reload_conf();');
    }

    /**
     * `tee`, not a shell redirect: it keeps owner and mode of `pg_hba.conf` (`postgres:postgres`),
     * while a redirect run as root creates a root-owned file the postmaster refuses to read.
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
     * Which interfaces the cluster is bound to. `localhost` (the default) means no remote client
     * can connect whatever `pg_hba.conf` says.
     */
    public function listenAddresses(): string
    {
        $result = $this->run('SHOW listen_addresses;');

        return $result->ok ? trim($result->output()) : 'localhost';
    }

    /**
     * Is the cluster already reachable from off the box? Anything but the loopback default
     * counts: the panel must not restart an operator's cluster to widen a setting they chose.
     */
    public function listensRemotely(): bool
    {
        $value = $this->listenAddresses();

        return $value !== '' && $value !== 'localhost' && $value !== '127.0.0.1' && $value !== '::1';
    }

    /**
     * Bind every interface, then restart (the only way this setting takes).
     *
     * Written with `ALTER SYSTEM` into `postgresql.auto.conf`, so the operator's own
     * `postgresql.conf` stays theirs. Binding is not granting: `pg_hba.conf` still needs a
     * record and the firewall must open 5432.
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

    /** Set by ALTER SYSTEM (postgresql.auto.conf), as openRemoteListening() does it. */
    public function remoteListeningOpenedByPanel(): bool
    {
        $result = $this->run("SELECT sourcefile FROM pg_settings WHERE name = 'listen_addresses';");

        return $result->ok && str_ends_with(trim($result->output()), 'postgresql.auto.conf');
    }

    /**
     * Resets the ALTER SYSTEM value, then restarts the cluster. A failed restart cannot be undone
     * with SQL (the cluster may be down), so the reference is all the caller gets.
     */
    public function closeRemoteListening(): void
    {
        // The cluster first: once the value is reset, a restart is the only way back.
        $cluster = $this->cluster();

        if ($cluster === null) {
            $reference = (string) Str::uuid();
            Log::channel('server-ops')->error('close_remote_listening: no cluster found', ['reference' => $reference, 'engine' => $this->engine()]);

            throw new DatabaseOperationException($reference);
        }

        $this->must('ALTER SYSTEM RESET listen_addresses;');

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
     * The cluster as `<version>-<name>`, discovered with `pg_lsclusters`: restarting the wrong
     * unit would report success having done nothing.
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

    /**
     * `$probe` is for the "is it here?" questions (available, version): exit 127 is `env`
     * finding no psql, which means "not installed", not a failed server command.
     */
    private function run(string $sql, bool $probe = false): ServerOpsResult
    {
        return $this->runIn(self::MAINTENANCE_DATABASE, $sql, $probe);
    }

    /**
     * Run SQL against one database.
     *
     *  - `--set=ON_ERROR_STOP=1` makes a failure a failure: SQL read from stdin exits 0 on an
     *    error without it. Every `must()` depends on this flag.
     *  - `-t -A -F` give tab-separated, header-free output; `-q` silences command tags.
     *
     * SQL goes over stdin and the password in a 0600 PGPASSFILE, so neither reaches argv
     * (readable in /proc).
     */
    private function runIn(string $database, string $sql, bool $probe = false): ServerOpsResult
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
                expectedExitCodes: $probe ? [self::CLIENT_MISSING] : [],
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
     * Host, port and user, never the password (it travels in PGPASSFILE). `--no-password` so a
     * client that cannot authenticate fails at once instead of blocking on a prompt.
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
     * A 0600 pgpass file: `host:port:database:user:password`. psql ignores a file with group or
     * world access. `*` for the database field: the admin credentials are used against every
     * database.
     */
    private function writePassFile(): string
    {
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $file = $dir.'/pg-'.bin2hex(random_bytes(8)).'.pgpass';

        $host = $this->connection->connection_type === 'socket' && $this->connection->socket
            ? (string) $this->connection->socket
            : (string) ($this->connection->host ?: '127.0.0.1');

        // Escape a literal colon or backslash, or it is read as a separator and shifts every
        // later field.
        $fields = array_map(
            fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value),
            [$host, (string) ((int) ($this->connection->port ?: 5432)), '*', (string) $this->connection->username, (string) $this->connection->password],
        );

        file_put_contents($file, implode(':', $fields)."\n");
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Double-quote an identifier (already regex-validated upstream). Required: unquoted names
     * fold to lower case, so a database created as `Shop` would be `shop`.
     */
    private function ident(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    /** A single-quoted string literal, quotes included, so no caller can forget to quote it. */
    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
