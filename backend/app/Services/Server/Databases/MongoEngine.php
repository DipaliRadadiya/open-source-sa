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

    public function listUsers(): array
    {
        // Mongo keeps users per database rather than server-wide, so the
        // database a user belongs to is not a grant to parse — it is where
        // the account lives.
        $result = $this->run(
            'db.adminCommand({ usersInfo: { forAllDBs: true } }).users'
            .'.forEach(u => print(u.user + "\t" + u.db));'
        );

        if ($result->failed()) {
            return [];
        }

        $system = (array) config('server.databases.system_users', []);
        $users = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$username, $database] = array_pad(preg_split('/\t/', trim($line)) ?: [], 2, '');
            $username = trim((string) $username);

            if ($username === '' || in_array($username, $system, true)) {
                continue;
            }

            $users[] = [
                'username' => $username,
                // Mongo authenticates against a database, not a host, so the
                // panel's `host` column has no counterpart. localhost is the
                // honest default rather than a value read from nowhere.
                'host' => 'localhost',
                'databases' => array_filter([trim((string) $database)]),
            ];
        }

        return $users;
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

    public function renameUser(string $username, string $host, string $newUsername, string $newHost, string $password, string $database): void
    {
        // Mongo has no RENAME — drop the old user and recreate with the same password.
        $this->dropUser($username, $host, $database);
        $this->createUser($newUsername, $newHost, $password, $database);
    }

    public function processes(): array
    {
        $result = $this->run(
            'db.adminCommand({ currentOp: 1 }).inprog.forEach(o => print(['
            .'o.opid, (o.client||""), (o.ns||""), (o.op||""), (o.secs_running||0), (o.desc||"")].join("\t")));'
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
                'user' => $c[5] ?? '',
                'host' => $c[1] ?? '',
                'db' => $c[2] ?? null,
                'command' => $c[3] ?? '',
                'time' => (int) ($c[4] ?? 0),
                'state' => '',
                'query' => null,
            ];
        }

        return $processes;
    }

    public function killProcess(string $id): void
    {
        $this->must('db.adminCommand({ killOp: 1, op: '.(int) $id.' });');
    }

    public function status(): array
    {
        $result = $this->run(
            'const s = db.serverStatus(); const o = s.opcounters || {}; '
            .'print([s.connections.current, (s.connections.available||0), s.uptime, '
            .'((o.insert||0)+(o.query||0)+(o.update||0)+(o.delete||0)+(o.command||0))].join("\t"));'
        );
        $c = $result->ok ? explode("\t", trim($result->output())) : [];

        return [
            'connections' => (int) ($c[0] ?? 0),
            'max_connections' => (int) ($c[1] ?? 0),
            'threads_running' => null,
            'queries' => (int) ($c[3] ?? 0),
            'slow_queries' => null,
            'uptime_seconds' => (int) ($c[2] ?? 0),
        ];
    }

    public function tables(string $database): array
    {
        $result = $this->run(
            'const d = db.getSiblingDB('.$this->js($database).'); '
            .'d.getCollectionNames().forEach(n => { const st = d.getCollection(n).stats(); '
            .'print([n, (st.count||0), (st.size||0)].join("\t")); });'
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
        $result = $this->run(
            'const o = db.serverStatus().opcounters || {}; '
            .'print((o.insert||0)+(o.query||0)+(o.update||0)+(o.delete||0)+(o.command||0));'
        );

        return $result->ok ? (int) trim($result->output()) : 0;
    }

    public function optimize(string $database): void
    {
        // MongoDB has no OPTIMIZE TABLE equivalent — no-op.
    }

    public function repair(string $database): void
    {
        // MongoDB has no REPAIR TABLE equivalent — no-op.
    }

    public function dump(string $database, string $path): void
    {
        $client = (string) config('server.databases.engines.mongodb.dump_client', 'mongodump');
        // mongodump can't read a defaults-file — put the password in a 0600
        // YAML config (`--config`), everything else on argv is non-secret.
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $configFile = $dir.'/db-'.bin2hex(random_bytes(8)).'.yaml';
        file_put_contents($configFile, 'password: '.json_encode((string) $this->connection->password)."\n");
        @chmod($configFile, 0600);

        try {
            $result = $this->serverOps->run([
                $client, '--config='.$configFile,
                '--host='.($this->connection->host ?: '127.0.0.1'),
                '--port='.(int) ($this->connection->port ?: 27017),
                '--username='.(string) $this->connection->username,
                '--authenticationDatabase='.(string) (($this->connection->options['authSource'] ?? null) ?: 'admin'),
                '--db='.$database, '--archive='.$path, '--gzip',
            ], ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'export'], 600);
            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            @unlink($configFile);
        }
    }

    public function restore(string $database, string $path): void
    {
        $client = (string) config('server.databases.engines.mongodb.restore_client', 'mongorestore');
        // Same 0600 YAML config as dump() — mongorestore cannot read a
        // defaults-file either, and the password must not reach argv.
        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $configFile = $dir.'/db-'.bin2hex(random_bytes(8)).'.yaml';
        file_put_contents($configFile, 'password: '.json_encode((string) $this->connection->password)."\n");
        @chmod($configFile, 0600);

        try {
            $result = $this->serverOps->run([
                $client, '--config='.$configFile,
                '--host='.($this->connection->host ?: '127.0.0.1'),
                '--port='.(int) ($this->connection->port ?: 27017),
                '--username='.(string) $this->connection->username,
                '--authenticationDatabase='.(string) (($this->connection->options['authSource'] ?? null) ?: 'admin'),
                // `--drop` per collection as well as the caller's drop of the
                // database: a collection present now but absent from the
                // archive would otherwise survive a "restore".
                '--nsInclude='.$database.'.*', '--drop', '--gzip', '--archive='.$path,
            ], ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'restore'], 3600);

            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            @unlink($configFile);
        }
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
