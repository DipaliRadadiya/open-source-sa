<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A database engine running as a container, for the container sites to use.
 *
 * Not an Application: every site is an HTTP site (domain, vhost, certificate), and a database
 * speaks its own wire protocol. It is a server-level object like a network or a volume.
 *
 * The credentials are `encrypted:array`, like a registry's token and a storage destination's keys.
 */
#[Fillable(['name', 'engine', 'version', 'port', 'credentials', 'docker_network', 'cpu_limit', 'memory_limit'])]
class DockerDatabase extends Model
{
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'port' => 'integer',
        ];
    }

    /**
     * The compose project, which is also the container name prefix.
     *
     * Derived from the id, not the name: a renamed project would be orphaned, still holding the port.
     */
    public function project(): string
    {
        return 'sv-db-'.$this->id;
    }

    /** The named volume holding the data directory. */
    public function volume(): string
    {
        return $this->project().'_data';
    }

    /**
     * One value out of the credential set, or the default when it cannot be read.
     *
     * Reading an `encrypted:array` attribute encrypted under another `APP_KEY` throws; without the
     * `try`, one such row would turn the whole listing into a 500.
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        try {
            $credentials = $this->credentials;
        } catch (DecryptException) {
            return $default;
        }

        return is_array($credentials) ? ($credentials[$key] ?? $default) : $default;
    }

    /**
     * The names of the credentials this database has, and nothing else.
     *
     * Lets a resource say which fields an engine uses (Redis has no user) without touching the
     * decrypted values.
     *
     * @return array<string, true>
     */
    public function credentialKeys(): array
    {
        try {
            $credentials = $this->credentials;
        } catch (DecryptException) {
            return [];
        }

        return is_array($credentials) ? array_fill_keys(array_keys($credentials), true) : [];
    }

    /**
     * The catalog entry for this engine, or null when the engine is unknown.
     *
     * Unknown is reachable (the catalog is config), and the listing should still show that row.
     *
     * @return array<string, mixed>|null
     */
    public function engineConfig(): ?array
    {
        $engine = (array) config('server.docker_databases.engines.'.$this->engine);

        return $engine === [] ? null : $engine;
    }

    /** The image this row's engine and version resolve to, or null. */
    public function image(): ?string
    {
        $image = $this->engineConfig()['versions'][$this->version] ?? null;

        return is_string($image) ? $image : null;
    }

    /**
     * Where this image keeps its data, which is not always the engine's default.
     *
     * Postgres 18 moved `PGDATA` to `/var/lib/postgresql/18/docker` (volume at `/var/lib/postgresql`);
     * 16 and 17 use `/var/lib/postgresql/data`. Mounting the old path at 18 makes the container
     * refuse to start. A per-version override, not a second engine entry, to keep one versions list.
     */
    public function dataPath(): string
    {
        $engine = (array) $this->engineConfig();
        $overrides = (array) ($engine['data_path_overrides'] ?? []);

        // Compare as strings: PHP turns the numeric key `'18'` into the int 18.
        foreach ($overrides as $version => $path) {
            if ((string) $version === (string) $this->version) {
                return (string) $path;
            }
        }

        return (string) ($engine['data_path'] ?? '');
    }

    /** The port the engine listens on INSIDE its container. */
    public function enginePort(): ?int
    {
        $port = $this->engineConfig()['port'] ?? null;

        return is_int($port) ? $port : null;
    }
}
