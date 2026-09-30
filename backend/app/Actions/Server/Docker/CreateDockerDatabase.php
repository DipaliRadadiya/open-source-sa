<?php

namespace App\Actions\Server\Docker;

use App\Models\DockerDatabase;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Docker\DatabaseContainerFailedException;
use App\Services\Server\Docker\DatabaseContainerManager;
use Illuminate\Support\Str;

/**
 * Create a containerised database and start it.
 *
 * Credentials are generated here and never asked for. A password somebody types
 * into a create form is a password that reaches the panel's logs, their shell
 * history and their clipboard, and the init-only rule means it can never be
 * changed afterwards anyway — so the panel generates one and shows it.
 *
 * **Every value here is init-only.** The engine images read `*_PASSWORD`,
 * `*_USER` and `*_DB` when the data directory is empty and ignore them
 * afterwards. That is why the row is written before the container starts and is
 * never regenerated: a rewritten file would disagree with the running engine while
 * looking perfectly correct.
 */
class CreateDockerDatabase
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private PortAllocator $ports,
        private DatabaseContainerManager $containers,
    ) {}

    /**
     * @param  array{name: string, engine: string, version: string, docker_network?: string|null}  $data
     *
     * @throws DatabaseContainerFailedException
     */
    public function execute(array $data): DockerDatabase
    {
        $database = DockerDatabase::create([
            'name' => $data['name'],
            'engine' => $data['engine'],
            'version' => $data['version'],
            'port' => $this->ports->allocate(),
            'docker_network' => $data['docker_network'] ?? null,
            'credentials' => $this->credentials($data['engine'], $data['name']),
        ]);

        try {
            $this->containers->apply($database);
        } catch (DatabaseContainerFailedException $e) {
            // The row goes with it. A database row whose container never started
            // is a set of connection details that connect to nothing, and it
            // holds a port the allocator would keep reserving.
            $this->containers->remove($database);
            $database->delete();

            throw $e;
        }

        // Names and the engine, never a credential. The activity log is readable
        // by a wider audience than the people who may manage Docker.
        $this->activityLogger->log('docker_database.created', $database, [
            'name' => $database->name,
            'engine' => $database->engine,
            'version' => $database->version,
            'port' => $database->port,
            'network' => $database->docker_network,
        ]);

        return $database;
    }

    /**
     * The credential set for an engine.
     *
     * Redis and Valkey have no users and no databases — a password is the whole of
     * their auth — so they get one, and the connection details show only what
     * exists rather than empty fields for concepts the engine does not have.
     *
     * The application user is never root. MySQL and MariaDB need root to create
     * the schema, so root's password is stored separately and shown separately;
     * nothing else should use it.
     *
     * @return array<string, string>
     */
    private function credentials(string $engine, string $name): array
    {
        $password = Str::random(32);

        if (in_array($engine, ['redis', 'valkey'], true)) {
            return ['password' => $password];
        }

        // A database and user named after the object, so connection details read
        // like something a person chose. Sanitised because an identifier is not a
        // display name: MySQL forbids a hyphen in an unquoted one, and a name is
        // free text.
        $identifier = Str::of($name)->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->limit(24, '')->toString();
        $identifier = $identifier === '' ? 'app' : $identifier;

        $credentials = [
            'username' => $identifier,
            'password' => $password,
            'database' => $identifier,
        ];

        if (in_array($engine, ['mysql', 'mariadb'], true)) {
            $credentials['root_password'] = Str::random(32);
        }

        return $credentials;
    }
}
