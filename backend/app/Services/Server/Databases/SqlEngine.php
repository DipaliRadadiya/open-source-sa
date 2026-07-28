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
        $this->must('DROP DATABASE '.$this->ident($name).';');
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
