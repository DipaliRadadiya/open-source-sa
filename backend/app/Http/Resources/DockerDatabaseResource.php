<?php

namespace App\Http\Resources;

use App\Services\Server\Docker\DatabaseContainerManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One containerised database, minus its passwords.
 *
 * The password is absent, not masked — a mask is a length disclosure — and it
 * comes from its own endpoint the way a container site's generated credentials do,
 * so it is not in every listing, every browser cache and every proxy log between
 * here and the page.
 *
 * What IS here is the half of the connection details that are not secret, and they
 * are worth having together: the host to use from another container is the
 * database's NAME, while the host to use from the server itself is 127.0.0.1 and
 * the port. Those two being different is the single most confusing thing about a
 * containerised database, so the API answers both rather than leaving the UI to
 * explain it.
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
            // Resolved from the catalog, so a row whose engine was removed from
            // config still lists — showing the raw key rather than breaking.
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

            // The size this instance was given. Null means the configured default
            // for memory and no quota for CPU — the UI has to say which, so the
            // raw value is what it gets rather than a resolved number that would
            // hide the difference between "chosen" and "inherited".
            'cpu_limit' => $this->cpu_limit,
            'memory_limit' => $this->memory_limit,
            'default_memory_limit' => (string) config('server.docker.default_db_memory_limit', '512m'),

            // Named without their values, so the UI can render the right fields
            // for the engine — Redis has no user and no database — and a
            // "credentials set" state without the secret going near a response.
            'credential_keys' => array_values(array_diff(
                array_keys((array) ($this->credentialKeys())),
                ['password', 'root_password'],
            )),
            'username' => $this->credential('username'),
            'database' => $this->credential('database'),
            'has_root_password' => $this->credential('root_password') !== null,

            // Asked of Docker, because a row is not a running container: a
            // database stopped by a reboot or an OOM must not read as healthy.
            'running' => app(DatabaseContainerManager::class)->running($this->resource),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
