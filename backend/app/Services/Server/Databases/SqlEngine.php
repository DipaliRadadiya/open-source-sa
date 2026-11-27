<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Contracts\ListensRemotely;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MySQL + MariaDB. Runs the `mysql`/`mariadb` client with connection creds in
 * a 0600 `--defaults-extra-file` and the SQL piped over stdin, so neither the
 * admin password nor any statement-embedded password is ever visible on argv.
 * Identifiers are back-tick quoted; string literals are escaped; both are also
 * strict-regex validated by the FormRequests.
 */
class SqlEngine implements DatabaseEngine, ListensRemotely
{
    public function __construct(
        private DatabaseConnection $connection,
        private ServerOps $serverOps,
        private ?ManagedFile $files = null,
    ) {}

    /**
     * Whether a remote user could connect at all.
     *
     * Ubuntu's packages ship `bind-address = 127.0.0.1`, so a "remote" user would exist but
     * never be reachable. Anything other than loopback counts: a specific interface bound by
     * the operator is their decision, and widening it is not ours to do.
     */
    public function listensRemotely(): bool
    {
        $result = $this->run('SELECT @@bind_address;');

        $addresses = array_filter(array_map('trim', explode(',', trim($result->output()))));

        // Unreadable counts as "already listening": never restart on a guess.
        if ($result->failed() || $addresses === []) {
            return true;
        }

        return array_diff($addresses, ['127.0.0.1', 'localhost', '::1']) !== [];
    }

    /**
     * Writes a drop-in after the package's own file (`50-server.cnf` / `mysqld.cnf`), then
     * restarts: `bind_address` is read-only at runtime on both engines.
     *
     * Load order is alphabetical and digits sort before letters, so the file is
     * `zz-panel-remote.cnf`; a `99-` name is read before `mysqld.cnf` and loses. Legacy files
     * are removed here. Binding is not granting: the account's host and the firewall rule are
     * the other two locks.
     */
    public function openRemoteListening(): void
    {
        $engine = $this->connection->engine;
        $dropIn = (string) config("server.databases.engines.{$engine}.remote_bind_file");
        $service = (string) config("server.databases.engines.{$engine}.service");
        $context = ['feature' => 'database', 'engine' => $engine, 'op' => 'open_remote_listening'];

        if ($dropIn === '' || $service === '') {
            throw new DatabaseOperationException(null);
        }

        $written = ($this->files ?? app(ManagedFile::class))->put(
            $dropIn,
            "# Managed by the panel: lets remote database users connect.\n[mysqld]\nbind-address = 0.0.0.0\n",
            $context,
        );

        if ($written->failed()) {
            throw new DatabaseOperationException($written->reference);
        }

        $legacy = array_values(array_filter(
            (array) config("server.databases.engines.{$engine}.legacy_remote_bind_files", []),
            fn ($path) => is_string($path) && $path !== '' && $path !== $dropIn,
        ));

        if ($legacy !== []) {
            $removed = $this->serverOps->run(['rm', '-f', ...$legacy], $context);

            if ($removed->failed()) {
                throw new DatabaseOperationException($removed->reference);
            }
        }

        $restart = $this->serverOps->run(['systemctl', 'restart', $service], $context, 120);

        if ($restart->failed()) {
            throw new DatabaseOperationException($restart->reference);
        }
    }

    /** The panel's drop-in (or an older name of it) is there: only then is closing ours to do. */
    public function remoteListeningOpenedByPanel(): bool
    {
        foreach ($this->remoteBindFiles() as $path) {
            $read = ($this->files ?? app(ManagedFile::class))->get($path, ['feature' => 'database', 'engine' => $this->connection->engine]);

            if ($read->ok && preg_match('/^\s*bind[-_]address\s*=/mi', $read->output()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the panel's drop-in, then restarts. A failed restart writes it back and restarts
     * again, so the engine is not left down by a change nobody needs.
     */
    public function closeRemoteListening(): void
    {
        $engine = $this->connection->engine;
        $dropIn = (string) config("server.databases.engines.{$engine}.remote_bind_file");
        $service = (string) config("server.databases.engines.{$engine}.service");
        $context = ['feature' => 'database', 'engine' => $engine, 'op' => 'close_remote_listening'];

        if ($dropIn === '' || $service === '') {
            throw new DatabaseOperationException(self::logReference('no remote_bind_file or service configured', $context));
        }

        $removed = $this->serverOps->run(['rm', '-f', ...$this->remoteBindFiles()], $context);

        if ($removed->failed()) {
            throw new DatabaseOperationException($removed->reference);
        }

        $restart = $this->serverOps->run(['systemctl', 'restart', $service], $context, 120);

        if ($restart->failed()) {
            ($this->files ?? app(ManagedFile::class))->put(
                $dropIn,
                "# Managed by the panel: lets remote database users connect.\n[mysqld]\nbind-address = 0.0.0.0\n",
                $context,
            );
            $this->serverOps->run(['systemctl', 'restart', $service], $context, 120);

            throw new DatabaseOperationException($restart->reference);
        }
    }

    /**
     * A reference for a failure no command produced, logged where the user can quote it.
     *
     * @param  array<string, mixed>  $context
     */
    private static function logReference(string $message, array $context): string
    {
        $reference = (string) Str::uuid();
        Log::channel('server-ops')->error($message, ['reference' => $reference, ...$context]);

        return $reference;
    }

    /** @return list<string> the drop-in and its legacy names */
    private function remoteBindFiles(): array
    {
        $engine = $this->connection->engine;

        return array_values(array_unique(array_filter([
            (string) config("server.databases.engines.{$engine}.remote_bind_file"),
            ...(array) config("server.databases.engines.{$engine}.legacy_remote_bind_files", []),
        ], fn ($path) => is_string($path) && $path !== '')));
    }

    public function engine(): string
    {
        return $this->connection->engine;
    }

    public function driver(): string
    {
        return 'sql';
    }

    /**
     * A question, not an operation: "no" is an answer, so it is not logged as an error.
     * A failure that matters shows up on the operation that needs the engine.
     */
    public function available(): bool
    {
        return $this->run('SELECT 1;', probe: true)->ok;
    }

    public function version(): ?string
    {
        $result = $this->run('SELECT VERSION();', probe: true);

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    public function listDatabases(): array
    {
        $result = $this->run('SHOW DATABASES;');
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
        // `mysql.user` rather than a SHOW: it is the only place that lists an account with no
        // grants at all.
        //
        // Two columns, not CONCAT(user, '\t', host): --batch mode escapes a tab inside a value
        // as a literal backslash-t, so the split would find no tab. Between columns it is real.
        $result = $this->run(
            "SELECT user, host FROM mysql.user WHERE user <> '';"
        );

        if ($result->failed()) {
            return [];
        }

        $system = (array) config('server.databases.system_users', []);
        $users = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$username, $host] = array_pad(preg_split('/\t/', $line) ?: [], 2, 'localhost');
            $username = trim((string) $username);

            if ($username === '' || in_array($username, $system, true)) {
                continue;
            }

            $users[] = [
                'username' => $username,
                'host' => trim((string) $host) ?: 'localhost',
                'databases' => $this->grantedDatabases($username, trim((string) $host)),
            ];
        }

        return $users;
    }

    public function identifierAvailable(string $name, string $host = 'localhost'): bool
    {
        $result = $this->run(sprintf(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = '%s') "
            ."OR EXISTS (SELECT 1 FROM mysql.user WHERE user = '%s' AND host = '%s') THEN 0 ELSE 1 END;",
            $this->esc($name),
            $this->esc($name),
            $this->esc($host),
        ));

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === '1';
    }

    public function userExists(string $username, string $host, string $database): bool
    {
        $result = $this->run(sprintf(
            "SELECT IF(EXISTS (SELECT 1 FROM mysql.user WHERE user = '%s' AND host = '%s'), 'user_exists', 'user_free');",
            $this->esc($username),
            $this->esc($host),
        ));

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === 'user_exists';
    }

    /**
     * The databases one account has been granted something on.
     *
     * Parsed from SHOW GRANTS: the only view that includes table-level grants. A `*.*` grant is
     * skipped: server-wide privileges mean an admin account, not a site's database user.
     *
     * @return array<int, string>
     */
    private function grantedDatabases(string $username, string $host): array
    {
        $result = $this->run(sprintf(
            "SHOW GRANTS FOR '%s'@'%s';",
            $this->esc($username),
            $this->esc($host ?: 'localhost'),
        ));

        if ($result->failed()) {
            return [];
        }

        $databases = [];

        // GRANT ... ON `shop`.* TO ... — backticks are optional depending on server version.
        if (preg_match_all('/\sON\s+`?([^`\s.]+)`?\.\S+\s+TO\s/i', $result->output(), $matches)) {
            foreach ($matches[1] as $database) {
                if ($database !== '*' && ! in_array($database, $databases, true)) {
                    $databases[] = $database;
                }
            }
        }

        return $databases;
    }

    public function createDatabase(string $name, ?string $charset, ?string $collation): void
    {
        $sql = 'CREATE DATABASE '.$this->ident($name);
        if ($charset) {
            $sql .= ' CHARACTER SET '.$this->ident($charset);
        }
        if ($collation) {
            $sql .= ' COLLATE '.$this->ident($collation);
        }
        $this->must($sql.';');
    }

    public function dropDatabase(string $name): void
    {
        // IF EXISTS: a retry after a half-finished teardown must continue, not fail here.
        $this->must('DROP DATABASE IF EXISTS '.$this->ident($name).';');
    }

    /**
     * Database first, then its users: dropping users first can leave a live database
     * unreachable if its drop then fails. `DROP USER` needs nothing from the dropped database
     * ({@see PgsqlEngine} is where that differs).
     *
     * @param  array<int, array{username: string, host: string}>  $users
     */
    public function teardownDatabase(string $name, array $users): void
    {
        $this->dropDatabase($name);

        foreach ($users as $user) {
            $this->dropUser($user['username'], $user['host'], $name);
        }
    }

    public function databaseSize(string $name): ?int
    {
        $result = $this->run(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables '
            ."WHERE table_schema = '".$this->esc($name)."';"
        );

        $output = trim($result->output());

        return $result->ok && is_numeric($output) ? (int) $output : null;
    }

    /**
     * One row, two tab-separated columns, parsed positionally: the batch client prints no header.
     *
     * @return array{charset: ?string, collation: ?string}
     */
    public function describeDatabase(string $name): array
    {
        $result = $this->run(
            'SELECT default_character_set_name, default_collation_name '
            ."FROM information_schema.schemata WHERE schema_name = '".$this->esc($name)."';"
        );

        if (! $result->ok) {
            return ['charset' => null, 'collation' => null];
        }

        $columns = preg_split('/\t/', trim($result->output())) ?: [];

        return [
            'charset' => ($columns[0] ?? '') !== '' ? $columns[0] : null,
            'collation' => ($columns[1] ?? '') !== '' ? $columns[1] : null,
        ];
    }

    public function createUser(string $username, string $host, string $password, string $database): void
    {
        $account = "'".$this->esc($username)."'@'".$this->esc($host)."'";
        $this->must(
            "CREATE USER {$account} IDENTIFIED BY '".$this->esc($password)."'; "
            .'GRANT ALL PRIVILEGES ON '.$this->ident($database).".* TO {$account}; "
            .'FLUSH PRIVILEGES;'
        );
    }

    public function dropUser(string $username, string $host, string $database): void
    {
        $this->must("DROP USER '".$this->esc($username)."'@'".$this->esc($host)."';");
    }

    public function setPassword(string $username, string $host, string $password, string $database): void
    {
        $this->must(
            "ALTER USER '".$this->esc($username)."'@'".$this->esc($host)."' "
            ."IDENTIFIED BY '".$this->esc($password)."'; FLUSH PRIVILEGES;"
        );
    }

    public function renameUser(string $username, string $host, string $newUsername, string $newHost, string $password, string $database): void
    {
        $this->must(
            "RENAME USER '".$this->esc($username)."'@'".$this->esc($host)."' "
            ."TO '".$this->esc($newUsername)."'@'".$this->esc($newHost)."'; FLUSH PRIVILEGES;"
        );
    }

    /**
     * Everything running except the connection doing the asking.
     *
     * `SHOW FULL PROCESSLIST` includes the thread running it, so the panel listed itself as an
     * active query (and offered a Stop Query that cannot work: the KILL runs on a new
     * connection). `information_schema.PROCESSLIST` has the same columns and lets the server
     * exclude our own connection; matching on statement text would misfire on a user's own
     * `SHOW FULL PROCESSLIST`.
     *
     * NULL is selected as the literal 'NULL' the batch client prints, so parsing is unchanged.
     */
    public function processes(): array
    {
        $result = $this->run(
            'SELECT ID, USER, HOST, IFNULL(DB, \'NULL\'), COMMAND, TIME, '
            .'IFNULL(STATE, \'\'), IFNULL(INFO, \'NULL\') '
            .'FROM information_schema.PROCESSLIST '
            .'WHERE ID <> CONNECTION_ID() ORDER BY TIME DESC;'
        );
        if ($result->failed()) {
            return [];
        }

        $processes = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            // Batch mode = tab-separated: Id User Host db Command Time State Info
            $c = explode("\t", $line);
            $processes[] = [
                'id' => $c[0] ?? '',
                'user' => $c[1] ?? '',
                'host' => $c[2] ?? '',
                'db' => ($c[3] ?? '') === 'NULL' ? null : ($c[3] ?? null),
                'command' => $c[4] ?? '',
                'time' => (int) ($c[5] ?? 0),
                'state' => $c[6] ?? '',
                'query' => ($c[7] ?? '') === 'NULL' ? null : ($c[7] ?? null),
            ];
        }

        return $processes;
    }

    public function killProcess(string $id): void
    {
        $this->must('KILL '.(int) $id.';');
    }

    public function status(): array
    {
        $vars = $this->statusMap('SHOW GLOBAL STATUS;');
        $maxConn = $this->statusMap("SHOW GLOBAL VARIABLES LIKE 'max_connections';");

        return [
            'connections' => (int) ($vars['Threads_connected'] ?? 0),
            'max_connections' => (int) ($maxConn['max_connections'] ?? 0),
            'threads_running' => (int) ($vars['Threads_running'] ?? 0),
            'queries' => (int) ($vars['Queries'] ?? 0),
            'slow_queries' => (int) ($vars['Slow_queries'] ?? 0),
            'uptime_seconds' => (int) ($vars['Uptime'] ?? 0),
        ];
    }

    public function tables(string $database): array
    {
        $result = $this->run(
            'SELECT table_name, COALESCE(table_rows,0), COALESCE(data_length+index_length,0) '
            ."FROM information_schema.tables WHERE table_schema = '".$this->esc($database)."' ORDER BY table_name;"
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

    public function dump(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.dump_client", 'mysqldump');
        $authFile = $this->writeAuthFile();

        try {
            // `--result-file` writes straight to disk; creds stay in the 0600 auth file.
            $result = $this->serverOps->run(
                [$client, '--defaults-extra-file='.$authFile, '--single-transaction', '--quick',
                    '--routines', '--triggers', '--result-file='.$path, $database],
                ['feature' => 'database', 'engine' => $this->connection->engine, 'op' => 'export'],
                600,
            );
            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            @unlink($authFile);
        }
    }

    /**
     * Grants name the database, not its contents, and survive a drop: nothing to give back.
     */
    public function reattachUsers(string $database, array $usernames): void {}

    /**
     * Runs as the database's own user with `--binary-mode`, which disables client commands
     * (except `\C` and `DELIMITER`) so `\!` or `system` in an uploaded file cannot reach a root
     * shell. Fed over stdin rather than `source`, which is itself a client command.
     */
    public function importAs(string $database, string $path, string $username, string $password, bool $gzip = false): void
    {
        $account = $this->connection->replicate();
        $account->username = $username;
        $account->password = $password;

        (new self($account, $this->serverOps))->load($database, $path);
    }

    private function load(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.client", 'mysql');
        $authFile = $this->writeAuthFile();
        $input = fopen($path, 'rb');

        try {
            $result = $this->serverOps->run(
                [$client, '--defaults-extra-file='.$authFile, '--batch', '--binary-mode', $database],
                ['feature' => 'database', 'engine' => $this->connection->engine, 'op' => 'import'],
                (int) config('server.databases.import_timeout', 7200),
                $input,
            );

            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            @unlink($authFile);
        }
    }

    public function restore(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.client", 'mysql');
        $authFile = $this->writeAuthFile();

        try {
            // `source` rather than stdin: a dump can exceed the PHP memory limit. The path is
            // ours (working directory + validated database name), nothing user-controlled.
            $result = $this->serverOps->run(
                [$client, '--defaults-extra-file='.$authFile, '--batch', $database,
                    '-e', 'source '.$path],
                ['feature' => 'database', 'engine' => $this->connection->engine, 'op' => 'restore'],
                3600,
            );

            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            @unlink($authFile);
        }
    }

    /**
     * Parse a two-column `SHOW … STATUS/VARIABLES` result into a name=>value map.
     *
     * @return array<string, string>
     */
    private function statusMap(string $sql): array
    {
        $result = $this->run($sql);
        if ($result->failed()) {
            return [];
        }

        $map = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $c = explode("\t", $line, 2);
            if (count($c) === 2) {
                $map[$c[0]] = $c[1];
            }
        }

        return $map;
    }

    private function must(string $sql): void
    {
        $result = $this->run($sql);
        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    private function run(string $sql, bool $probe = false): ServerOpsResult
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.client", 'mysql');
        $authFile = $this->writeAuthFile();

        try {
            return $this->serverOps->run(
                [$client, '--defaults-extra-file='.$authFile, '--batch', '--skip-column-names'],
                ['feature' => 'database', 'engine' => $this->connection->engine, 'op' => 'query'],
                60,
                $sql, // statements over stdin — never on argv
                // 127: `env` found no client, so the engine is not installed.
                expectedExitCodes: $probe ? [1, 127] : [],
            );
        } finally {
            @unlink($authFile);
        }
    }

    /**
     * A 0600 client auth file so the admin password is never passed on argv.
     */
    private function writeAuthFile(): string
    {
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $file = $dir.'/db-'.bin2hex(random_bytes(8)).'.cnf';

        $lines = ['[client]', 'user="'.addslashes((string) $this->connection->username).'"'];
        if ($this->connection->password !== null && $this->connection->password !== '') {
            $lines[] = 'password="'.addslashes((string) $this->connection->password).'"';
        }
        if ($this->connection->connection_type === 'socket' && $this->connection->socket) {
            $lines[] = 'socket="'.addslashes((string) $this->connection->socket).'"';
        } else {
            $lines[] = 'host="'.addslashes((string) ($this->connection->host ?: '127.0.0.1')).'"';
            $lines[] = 'port='.(int) ($this->connection->port ?: 3306);
        }

        file_put_contents($file, implode("\n", $lines)."\n");
        @chmod($file, 0600);

        return $file;
    }

    /** Back-tick quote an identifier (already regex-validated upstream). */
    private function ident(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }

    /** Escape a MySQL string literal. */
    private function esc(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
