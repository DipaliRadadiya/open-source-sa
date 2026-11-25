<?php

namespace App\Actions\Server\Docker;

use App\Models\DockerDatabase;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Docker\DatabaseContainerFailedException;
use App\Services\Server\Docker\DatabaseContainerManager;
use App\Services\Server\Docker\DockerResources;
use Illuminate\Support\Str;

/**
 * Create a containerised database and start it.
 *
 * Credentials are generated here and never asked for, so a typed password cannot reach the
 * panel's logs or shell history.
 *
 * Every value is init-only: the engine images read `*_PASSWORD`, `*_USER` and `*_DB` only when the
 * data directory is empty. So the row is written before the container starts and never
 * regenerated: a rewritten value would disagree with the running engine.
 */
class CreateDockerDatabase
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private PortAllocator $ports,
        private DatabaseContainerManager $containers,
        private DockerResources $docker,
    ) {}

    /**
     * @param  array{name: string, engine: string, version: string, docker_network?: string|null, cpu_limit?: string|null, memory_limit?: string|null}  $data
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
            // Null means the configured default for memory and no quota for CPU;
            // `DatabaseContainerManager` resolves both.
            'cpu_limit' => $data['cpu_limit'] ?? null,
            'memory_limit' => $data['memory_limit'] ?? null,
            'credentials' => $this->credentials($data['engine'], $data['name']),
        ]);

        try {
            $this->containers->apply($database);
        } catch (DatabaseContainerFailedException $e) {
            // The row goes with it: a row whose container never started connects to nothing and
            // holds a port. The create's failure is the one thrown, so the cleanup is best-effort.
            try {
                $this->containers->remove($database);
            } catch (DatabaseContainerFailedException) {
            }

            // The volume too, or a failed create leaves it behind. Safe to remove unconditionally
            // here (unlike on delete, where it is opt-in): it was created seconds ago and never
            // held data. Always after `remove()`: `docker volume rm` refuses a volume a container
            // still references.
            $this->docker->removeVolume($database->volume());

            $database->delete();

            throw $e;
        }

        // Never a credential: the activity log is readable by more people than may manage Docker.
        $this->activityLogger->log('docker_database.created', $database, [
            'name' => $database->name,
            'engine' => $database->engine,
            'version' => $database->version,
            'port' => $database->port,
            'network' => $database->docker_network,
            'cpu_limit' => $database->cpu_limit,
            'memory_limit' => $database->memory_limit,
        ]);

        return $database;
    }

    /**
     * The credential set for an engine.
     *
     * Redis and Valkey have no users or databases, only a password. The application user is never
     * root: MySQL and MariaDB's root password is stored separately and nothing else should use it.
     *
     * @return array<string, string>
     */
    private function credentials(string $engine, string $name): array
    {
        $password = Str::random(32);

        if (in_array($engine, ['redis', 'valkey'], true)) {
            return ['password' => $password];
        }

        // Database and user are named after the object, sanitised: the name is free text and
        // MySQL forbids a hyphen in an unquoted identifier.
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
