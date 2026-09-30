<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A database engine running as a container, for the container sites to use.
 *
 * **Why this is not an Application.** Every site in this panel is an HTTP site:
 * `domain` is required, provisioning always writes a vhost, and the container
 * vhost is `proxy_pass http://127.0.0.1:<port>`. A database speaks its own wire
 * protocol, so as a "site" it would hold a domain, be issued a certificate no
 * browser can use, and answer 502 for ever. It is a server-level object like a
 * network or a volume — something sites connect to — which is why it lives on the
 * Docker page and not in the applications list.
 *
 * **It exists because a Docker box manages no engine.** That is the stack's own
 * decision: an application that wants a database brings one as a container. Which
 * is fine until two sites want the same database, or an app needs Redis as well —
 * the point at which "bring your own" means pasting the same service into every
 * compose file and having no idea which volume belongs to whom.
 *
 * The credentials are `encrypted:array`, like a registry's token and a storage
 * destination's keys, and read in one place.
 */
#[Fillable(['name', 'engine', 'version', 'port', 'credentials', 'docker_network'])]
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
     * Derived from the id and not the name, for the reason a site's project is:
     * a name can be renamed and a compose project cannot follow it — the old
     * project would be orphaned, still running, still holding the port.
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
     * The `try` is not defensive noise: reading an `encrypted:array` attribute
     * whose ciphertext does not belong to this `APP_KEY` throws, so a database
     * restored under a different key would turn the listing — which only wants a
     * name and an engine — into a 500.
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
     * Exists so a resource can say which FIELDS an engine uses — Redis has no
     * user and no database — without a caller reaching into the decrypted array
     * and accidentally serialising a value out of it.
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
     * Unknown is reachable: the catalog is config, so an operator can remove an
     * engine a row still refers to. Callers get null rather than an exception,
     * because a listing should still show the row that needs attention.
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

    /** The port the engine listens on INSIDE its container. */
    public function enginePort(): ?int
    {
        $port = $this->engineConfig()['port'] ?? null;

        return is_int($port) ? $port : null;
    }
}
