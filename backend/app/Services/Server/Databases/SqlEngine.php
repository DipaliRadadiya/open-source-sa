<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * MySQL + MariaDB. Runs the `mysql`/`mariadb` client with connection creds in
 * a 0600 `--defaults-extra-file` and the SQL piped over stdin, so neither the
 * admin password nor any statement-embedded password is ever visible on argv.
 * Identifiers are back-tick quoted; string literals are escaped; both are also
 * strict-regex validated by the FormRequests.
 */
class SqlEngine implements DatabaseEngine
{
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
        return 'sql';
    }

    public function available(): bool
    {
        return $this->run('SELECT 1;')->ok;
    }

    public function version(): ?string
    {
        $result = $this->run('SELECT VERSION();');

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    public function listDatabases(): array
    {
        $result = $this->run('SHOW DATABASES;');
        if ($result->failed()) {
            return [];
        }

        $system = (array) config('server.databases.system_schemas.sql', []);

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($result->output())) ?: []),
            fn (string $db) => $db !== '' && ! in_array($db, $system, true),
        ));
    }

    public function listUsers(): array
    {
        // `mysql.user` rather than a SHOW: it is the only place that lists an
        // account with no grants at all, and an account nobody can see is
        // exactly the one worth surfacing on a migrated box.
        $result = $this->run(
            "SELECT CONCAT(user, '\t', host) FROM mysql.user WHERE user <> '';"
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

    /**
     * The databases one account has been granted something on.
     *
     * Parsed from SHOW GRANTS rather than information_schema, because that is
     * the only view that accounts for grants made at the table level as well
     * as the database level. A `*.*` grant is skipped: an account with
     * server-wide privileges is an admin account, not a site's database user,
     * and attaching it to one database would misdescribe it.
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

        // GRANT ... ON `shop`.* TO ... — the backticks are optional in the
        // output depending on server version, so both forms are matched.
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
        // A deletion can have dropped the schema but failed while cleaning up
        // its owned users. Retrying must finish that cleanup, not fail here.
        $this->must('DROP DATABASE IF EXISTS '.$this->ident($name).';');
    }

    public function databaseSize(string $name): int
    {
        $result = $this->run(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables '
            ."WHERE table_schema = '".$this->esc($name)."';"
        );

        return $result->ok ? (int) trim($result->output()) : 0;
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

    public function processes(): array
    {
        $result = $this->run('SHOW FULL PROCESSLIST;');
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

    public function queryCount(): int
    {
        return (int) ($this->statusMap('SHOW GLOBAL STATUS;')['Queries'] ?? 0);
    }

    public function optimize(string $database): void
    {
        $this->maintain($database, 'OPTIMIZE');
    }

    public function repair(string $database): void
    {
        $this->maintain($database, 'REPAIR');
    }

    public function dump(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.dump_client", 'mysqldump');
        $authFile = $this->writeAuthFile();

        try {
            // `--result-file` writes straight to disk (no stdout buffering) and
            // the creds live in the 0600 auth file — nothing sensitive on argv.
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

    public function restore(string $database, string $path): void
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.client", 'mysql');
        $authFile = $this->writeAuthFile();

        try {
            // `source` rather than piping the file in on stdin: a site dump is
            // routinely larger than the whole PHP memory limit, and reading it
            // into a string to pass as process input would kill the worker on
            // exactly the databases that most need restoring. The path is ours
            // (working directory + an upstream-validated database name), so
            // nothing user-controlled reaches the client's parser.
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

    private function maintain(string $database, string $verb): void
    {
        $names = array_map(fn (array $t) => $this->ident($t['name']), $this->tables($database));
        if ($names === []) {
            return;
        }
        $this->must("USE {$this->ident($database)}; {$verb} TABLE ".implode(', ', $names).';');
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

    private function run(string $sql): ServerOpsResult
    {
        $client = (string) config("server.databases.engines.{$this->connection->engine}.client", 'mysql');
        $authFile = $this->writeAuthFile();

        try {
            return $this->serverOps->run(
                [$client, '--defaults-extra-file='.$authFile, '--batch', '--skip-column-names'],
                ['feature' => 'database', 'engine' => $this->connection->engine],
                60,
                $sql, // statements over stdin — never on argv
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
