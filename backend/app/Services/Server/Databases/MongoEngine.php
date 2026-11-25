<?php

namespace App\Services\Server\Databases;

use App\Contracts\DatabaseEngine;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseConnection;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Validation\ValidationException;

/**
 * MongoDB via `mongosh`. The connection URI (with credentials) and the
 * operation are written into a 0600 `.js` file executed with
 * `mongosh --quiet --nodb --file`, so credentials are never on argv. All
 * interpolated values go through json_encode → safe JS string literals.
 */
class MongoEngine implements DatabaseEngine
{
    /** Printed by createDatabase() when the name is already on the server. */
    private const EXISTS_MARKER = 'panel:database_exists';

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

    /**
     * A question, so "no" is an answer. Without mongosh, sudo refuses the run (a real error),
     * so the client is looked for first and a server without MongoDB answers no quietly.
     */
    public function available(): bool
    {
        return $this->clientPresent() && $this->run('db.adminCommand({ ping: 1 });')->ok;
    }

    public function version(): ?string
    {
        if (! $this->clientPresent()) {
            return null;
        }

        $result = $this->run('print(db.version());');

        return $result->ok ? (trim($result->output()) ?: null) : null;
    }

    public function listDatabases(): array
    {
        $result = $this->run('db.adminCommand({ listDatabases: 1 }).databases.forEach(d => print(d.name));');
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
        // Mongo keeps users per database: the database is where the account lives, not a grant.
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
                // Mongo authenticates against a database, not a host: default to localhost.
                'host' => 'localhost',
                'databases' => array_filter([trim((string) $database)]),
            ];
        }

        return $users;
    }

    public function identifierAvailable(string $name, string $host = 'localhost'): bool
    {
        $quoted = $this->js($name);
        $result = $this->run(
            'const candidate = '.$quoted.'; '
            .'const databaseExists = db.adminCommand({ listDatabases: 1, nameOnly: true }).databases'
            .'.some(d => d.name === candidate); '
            .'const userExists = db.getSiblingDB(candidate).getUser(candidate) !== null; '
            .'print(databaseExists || userExists ? "0" : "1");'
        );

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === '1';
    }

    public function userExists(string $username, string $host, string $database): bool
    {
        // A user belongs to one database; the same name may exist in another.
        $result = $this->run('print(db.getSiblingDB('.$this->js($database).').getUser('.$this->js($username).') !== null ? "user_exists" : "user_free");');

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }

        return trim($result->output()) === 'user_exists';
    }

    public function createDatabase(string $name, ?string $charset, ?string $collation): void
    {
        // Refused when the name already exists: Mongo's createCollection would quietly join the
        // existing database, which a later failed step would then drop with its data. Checked in
        // the same script as the create, so a failed check is a failed create.
        //
        // Mongo creates a DB lazily; make a placeholder collection so it lists.
        $result = $this->run(
            'const candidate = '.$this->js($name).'; '
            .'if (db.adminCommand({ listDatabases: 1, nameOnly: true }).databases.some(d => d.name === candidate)) { '
            .'print("'.self::EXISTS_MARKER.'"); quit(3); } '
            .'db.getSiblingDB(candidate).createCollection("_panel_init");'
        );

        if ($result->failed() && str_contains($result->output(), self::EXISTS_MARKER)) {
            throw ValidationException::withMessages([
                'name' => [__('errors/database.exists_on_server', ['name' => $name])],
            ]);
        }

        if ($result->failed()) {
            throw new DatabaseOperationException($result->reference);
        }
    }

    public function dropDatabase(string $name): void
    {
        $this->must('db.getSiblingDB('.$this->js($name).').dropDatabase();');
    }

    /**
     * Database first, then its users, matching the SQL engines. Mongo is believed to keep
     * accounts outside the database they authenticate against, so each is dropped by name
     * (documented behaviour, not verified here).
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
        $result = $this->run('print(db.getSiblingDB('.$this->js($name).').stats().dataSize);');

        $output = trim($result->output());

        return $result->ok && is_numeric($output) ? (int) $output : null;
    }

    /**
     * Nothing to describe: MongoDB has no database-level character set or collation (BSON is
     * UTF-8; a collation belongs to an index or query). Null, not an invented "UTF8";
     * `createDatabase()` ignores both arguments for the same reason.
     *
     * @return array{charset: ?string, collation: ?string}
     */
    public function describeDatabase(string $name): array
    {
        return ['charset' => null, 'collation' => null];
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

    /**
     * Everything running except this connection's own operations.
     *
     * `currentOp` reports the operation asking for it (see {@see SqlEngine::processes()}).
     * `$ownOps` names our connection server-side, so an operator's own `currentOp` stays visible.
     *
     * The connection id is compared as a string: BSON `Long`s with the same number are
     * different objects, so `!==` on them is always true and would filter nothing.
     */
    public function processes(): array
    {
        $result = $this->run(
            'const own = String(((db.adminCommand({ currentOp: 1, $ownOps: true }).inprog[0]) || {}).connectionId); '
            .'db.adminCommand({ currentOp: 1 }).inprog'
            .'.filter(o => String(o.connectionId) !== own)'
            .'.forEach(o => print(['
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

    /**
     * The opcounters are summed in PHP, not in the shell: mongosh keeps int64 values as BSON
     * `Long` objects (`promoteLongs: false`), and `+` on them concatenates digits ("010050000"
     * instead of 50001). `(o.insert||0)` does not help, as `Long(0)` is truthy. Printing each
     * counter on its own line leaves the arithmetic to PHP, as {@see SqlEngine::status()} does.
     */
    public function status(): array
    {
        $result = $this->run(
            'const s = db.serverStatus(); const o = s.opcounters || {}; '
            .'[s.connections.current, (s.connections.available||0), s.uptime, '
            .'o.insert, o.query, o.update, o.delete, o.command]'
            .'.forEach(v => print(String(v == null ? 0 : v)));'
        );
        $c = $result->ok ? preg_split('/\r?\n/', trim($result->output())) : [];
        $c = is_array($c) ? $c : [];

        return [
            'connections' => (int) ($c[0] ?? 0),
            'max_connections' => (int) ($c[1] ?? 0),
            'threads_running' => null,
            'queries' => array_sum(array_map(
                fn (int $i): int => (int) trim((string) ($c[$i] ?? 0)),
                [3, 4, 5, 6, 7],
            )),
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

    public function dump(string $database, string $path): void
    {
        $client = (string) config('server.databases.engines.mongodb.dump_client', 'mongodump');
        // mongodump can't read a defaults-file: the password goes in a 0600 YAML `--config`.
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

    /**
     * Runs as the database's own user (`readWrite` on it alone). The archive's source database
     * is renamed into this one (`$db$.$coll$` → `{database}.$coll$`); the server's own
     * databases are skipped.
     */
    public function importAs(string $database, string $path, string $username, string $password, bool $gzip = false): void
    {
        $client = (string) config('server.databases.engines.mongodb.restore_client', 'mongorestore');

        $dir = rtrim((string) config('server.databases.auth_file_dir', sys_get_temp_dir()), '/');
        $configFile = $dir.'/db-'.bin2hex(random_bytes(8)).'.yaml';
        file_put_contents($configFile, 'password: '.json_encode($password)."\n");
        @chmod($configFile, 0600);

        try {
            $result = $this->serverOps->run(array_values(array_filter([
                $client, '--config='.$configFile,
                '--host='.($this->connection->host ?: '127.0.0.1'),
                '--port='.(int) ($this->connection->port ?: 27017),
                '--username='.$username,
                '--authenticationDatabase='.$database,
                '--nsExclude=admin.*', '--nsExclude=config.*', '--nsExclude=local.*',
                '--nsFrom=$db$.$coll$', '--nsTo='.$database.'.$coll$',
                '--drop',
                $gzip ? '--gzip' : null,
                '--archive='.$path,
            ])), ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'import'], (int) config('server.databases.import_timeout', 7200));

            if ($result->failed()) {
                throw new DatabaseOperationException($result->reference);
            }
        } finally {
            @unlink($configFile);
        }
    }

    /** Users are stored outside the database, so a drop and create leaves them in place. */
    public function reattachUsers(string $database, array $usernames): void {}

    public function restore(string $database, string $path): void
    {
        $client = (string) config('server.databases.engines.mongodb.restore_client', 'mongorestore');
        // Same 0600 YAML config as dump(): the password must not reach argv.
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
                // `--drop` per collection too: one absent from the archive would survive.
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

    private ?bool $clientPresent = null;

    private function clientPresent(): bool
    {
        return $this->clientPresent ??= $this->serverOps->probe(
            ['which', (string) config('server.databases.engines.mongodb.client', 'mongosh')],
            ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'detect_client'],
        )->ok;
    }

    private function run(string $script): ServerOpsResult
    {
        $client = (string) config('server.databases.engines.mongodb.client', 'mongosh');
        $file = $this->writeScript($script);

        try {
            return $this->serverOps->run(
                [$client, '--quiet', '--nodb', '--file', $file],
                ['feature' => 'database', 'engine' => 'mongodb', 'op' => 'query'],
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
