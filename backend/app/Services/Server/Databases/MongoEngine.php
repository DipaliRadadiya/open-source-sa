<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * MongoDB via `mongosh`. The connection URI (with credentials) and the
 * operation are written into a 0600 `.js` file executed with
 * `mongosh --quiet --nodb --file`, so credentials are never on argv. All
 * interpolated values go through json_encode → safe JS string literals.
 */
class MongoEngine implements DatabaseEngine
{
    public function __construct(
        private DatabaseConnection $connection,
        private ServerOps $serverOps,
    ) {}

    public function engine(): string
    {
        return 'mongodb';
    }

    public function driver(): string
    {
        return 'mongo';
    }

    public function available(): bool
    {
        return $this->run('db.adminCommand({ ping: 1 });')->ok;
    }

    public function version(): ?string
    {
        $result = $this->run('print(db.version());');

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    public function listDatabases(): array
    {
        $result = $this->run('db.adminCommand({ listDatabases: 1 }).databases.forEach(d => print(d.name));');
        if ($result->failed()) {
            return [];
        }

        $system = (array) config('server.databases.system_schemas.mongo', []);

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($result->output())) ?: []),
            fn (string $db) => $db !== '' && ! in_array($db, $system, true),
        ));
    }

    public function createDatabase(string $name, ?string $charset, ?string $collation): void
    {
        // Mongo creates a DB lazily; make a placeholder collection so it lists.
        $this->must('db.getSiblingDB('.$this->js($name).').createCollection("_panel_init");');
    }

    public function dropDatabase(string $name): void
    {
        $this->must('db.getSiblingDB('.$this->js($name).').dropDatabase();');
    }

    public function databaseSize(string $name): int
    {
        $result = $this->run('print(db.getSiblingDB('.$this->js($name).').stats().dataSize);');

        return $result->ok ? (int) trim($result->output()) : 0;
    }

    public function createUser(string $username, string $host, string $password, string $database): void
    {
        $this->must(
            'db.getSiblingDB('.$this->js($database).').createUser({ user: '.$this->js($username)
            .', pwd: '.$this->js($password).', roles: [{ role: "readWrite", db: '.$this->js($database).' }] });'
        );
    }

    public function dropUser(string $username, string $host, string $database): void
    {
        $this->must('db.getSiblingDB('.$this->js($database).').dropUser('.$this->js($username).');');
    }

    public function setPassword(string $username, string $host, string $password, string $database): void
    {
        $this->must(
            'db.getSiblingDB('.$this->js($database).').changeUserPassword('
            .$this->js($username).', '.$this->js($password).');'
        );
    }

    private function must(string $script): void
    {
        $result = $this->run($script);
        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    private function run(string $script): ServerOpsResult
    {
        $client = (string) config('server.databases.engines.mongodb.client', 'mongosh');
        $file = $this->writeScript($script);

        try {
            return $this->serverOps->run(
                [$client, '--quiet', '--nodb', '--file', $file],
                ['feature' => 'database', 'engine' => 'mongodb'],
            );
        } finally {
            @unlink($file);
        }
    }

    /**
     * A 0600 JS file: connect with the admin URI, then run the operation.
     * Credentials live in the file (0600), never on argv.
     */
    private function writeScript(string $body): string
    {
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $file = $dir.'/db-'.bin2hex(random_bytes(8)).'.js';

        $script = 'const db = connect('.$this->js($this->uri()).');'."\n".$body."\n";
        file_put_contents($file, $script);
        @chmod($file, 0600);

        return $file;
    }

    private function uri(): string
    {
        $user = rawurlencode((string) $this->connection->username);
        $pass = rawurlencode((string) $this->connection->password);
        $host = $this->connection->host ?: '127.0.0.1';
        $port = (int) ($this->connection->port ?: 27017);
        $authSource = (string) (($this->connection->options['authSource'] ?? null) ?: 'admin');

        $credentials = $user !== '' ? "{$user}:{$pass}@" : '';

        return "mongodb://{$credentials}{$host}:{$port}/admin?authSource={$authSource}";
    }

    /** Safe JS string literal for any interpolated value. */
    private function js(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
