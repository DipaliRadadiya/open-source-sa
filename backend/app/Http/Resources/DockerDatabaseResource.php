<?php

namespace App\Http\Resources;

use App\Services\Server\Docker\DatabaseContainerManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One containerised database, minus its passwords.
 *
 * The password is absent, not masked (a mask discloses its length). It comes from its own
 * endpoint, so it stays out of listings, browser caches and proxy logs.
 *
 * The non-secret connection details are here, both ways in: from another container the
 * host is the database's NAME; from the server itself it is 127.0.0.1 and the port.
 */
class DockerDatabaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $engine = $this->engineConfig();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'engine' => $this->engine,
            // From the catalog; a row whose engine left the config still lists with the raw key.
            'engine_label' => $engine['label'] ?? $this->engine,
            'version' => $this->version,
            'image' => $this->image(),

            // From another container on the same network: the alias, and the port
            // the engine listens on inside itself.
            'internal_host' => $this->name,
            'internal_port' => $this->enginePort(),

            // From the server itself, or through an SSH tunnel. Never from
            // anywhere else: the publish is bound to 127.0.0.1.
            'host_port' => $this->port,

            'network' => $this->docker_network,

            // Raw values on purpose: null means the configured default for memory and no quota
            // for CPU, which the UI must tell apart from a chosen value.
            'cpu_limit' => $this->cpu_limit,
            'memory_limit' => $this->memory_limit,
            'default_memory_limit' => (string) config('server.docker.default_db_memory_limit', '512m'),

            // Names only, never values: lets the UI render the engine's fields (Redis has no
            // user or database) without a secret in the response.
            'credential_keys' => array_values(array_diff(
                array_keys((array) ($this->credentialKeys())),
                ['password', 'root_password'],
            )),
            'username' => $this->credential('username'),
            'database' => $this->credential('database'),
            'has_root_password' => $this->credential('root_password') !== null,

            // Asked of Docker: a row is not a running container (reboot, OOM).
            'running' => app(DatabaseContainerManager::class)->running($this->resource),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
