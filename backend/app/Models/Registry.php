<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A container registry the panel can authenticate to.
 *
 * Server-level, like a storage destination and for the same reason: a
 * credential is set up once, before any site needs it, and then chosen by as
 * many sites as want it. A per-application field would mean re-typing a token
 * for every site and rotating it in N places.
 *
 * **The credential never leaves this class as a value anybody asks for.** It is
 * read in exactly one place — `RegistryAuth`, which writes it into a root-owned
 * `config.json` for the length of one pull. `RegistryResource` reports only
 * whether it is populated. That is the whole security design: encrypted at
 * rest, one reader, scoped to one command.
 */
#[Fillable(['name', 'registry', 'username', 'config'])]
class Registry extends Model
{
    /**
     * Docker's own name for the Hub, and what `config.json` must be keyed on.
     *
     * Not a cosmetic detail: a `config.json` keyed `docker.io` is ignored for a
     * Hub pull, because the daemon looks up the legacy v1 index URL. Measured,
     * not assumed — a hand-written file under this exact key authenticates a
     * private Hub repository, and the same file under `docker.io` does not.
     */
    public const HUB_KEY = 'https://index.docker.io/v1/';

    /**
     * The hosts that mean "Docker Hub", all of which users type.
     */
    private const HUB_HOSTS = ['', 'docker.io', 'index.docker.io', 'registry-1.docker.io', self::HUB_KEY];

    protected function casts(): array
    {
        return [
            // The credential set, encrypted as one document. See the migration
            // for why nothing inside it may be pre-encrypted.
            'config' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_success' => 'boolean',
        ];
    }

    /**
     * The sites pulling with this credential.
     *
     * Read to answer "what breaks if I delete this", which is the question the
     * UI has to answer before offering the button — the same shape the Docker
     * page's volume and network rows already use.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * One value out of the credential set.
     *
     * Exists for the same two reasons `StorageDestination::configValue()` does:
     * callers do not repeat `$config['x'] ?? null`, and a row whose config
     * failed to decrypt — a database restored under a different `APP_KEY` —
     * answers null instead of throwing a TypeError inside a queue worker.
     */
    public function configValue(string $key, mixed $default = null): mixed
    {
        $config = $this->config;

        if (! is_array($config)) {
            return $default;
        }

        return $config[$key] ?? $default;
    }

    /**
     * Merge new values in, keeping what was not supplied.
     *
     * This is what makes a PATCH mean "rotate the token I sent, leave the rest"
     * rather than "replace the document and clear everything absent from this
     * request". Null counts as absent, because the API's contract is that
     * omission preserves — a form that renders an empty password box must not
     * wipe a working credential on save.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeConfig(array $values): void
    {
        $this->config = array_merge(
            is_array($this->config) ? $this->config : [],
            array_filter($values, fn ($value): bool => $value !== null),
        );
    }

    /**
     * Is there a token stored at all?
     *
     * Only emptiness is reported, never the value — this is what lets the UI
     * show "credentials set" and a rotation prompt without the secret going
     * anywhere near a response.
     */
    public function hasCredentials(): bool
    {
        return trim((string) $this->configValue('token', '')) !== '';
    }

    /**
     * What the panel currently knows about this registry.
     *
     * `never_tested` is deliberately distinct from `failed`: "we have not
     * asked" and "we asked and it refused" are different situations, and only
     * one of them is the user's problem to fix.
     */
    public function testStatus(): string
    {
        if ($this->last_test_success === null) {
            return 'never_tested';
        }

        return $this->last_test_success ? 'connected' : 'failed';
    }

    /**
     * Forget what the last probe found.
     *
     * Called whenever the credential or the address changes. A stored
     * "connected" describes the token that was tested, not the one now stored,
     * and a green tick beside a credential rotated ten seconds ago lies about
     * the one thing this field exists to answer.
     */
    public function forgetTestResult(): void
    {
        $this->last_tested_at = null;
        $this->last_test_success = null;
        $this->last_test_error = null;
    }

    /**
     * The key this registry's credentials must be written under in config.json.
     *
     * Every non-Hub registry is keyed by its bare host — `ghcr.io`,
     * `registry.example.com:5000`. Hub is the exception and the one that bites:
     * see {@see self::HUB_KEY}.
     */
    public function authKey(): string
    {
        $host = $this->normalisedHost();

        return in_array($host, self::HUB_HOSTS, true) ? self::HUB_KEY : $host;
    }

    /**
     * Is this registry Docker Hub, however the user spelled it?
     *
     * Needed by the login probe, which must be given no argument at all for Hub
     * — `docker login docker.io` is accepted but `docker login` is what the
     * daemon's own default path uses.
     */
    public function isDockerHub(): bool
    {
        return in_array($this->normalisedHost(), self::HUB_HOSTS, true);
    }

    /**
     * The host, with the scheme and any path a user pasted taken back off.
     *
     * People paste `https://ghcr.io` and `ghcr.io/myorg` because both look like
     * the thing they are configuring. Neither is a config.json key, and a
     * mismatched key is silently ignored rather than rejected — the pull simply
     * behaves as if no credential were stored, which is the hardest possible
     * failure to diagnose. So this is normalisation, not validation: the form
     * still refuses what it cannot interpret.
     */
    private function normalisedHost(): string
    {
        $value = trim((string) $this->registry);

        if ($value === '') {
            return '';
        }

        // Keep the legacy Hub key intact — it IS a URL with a path, and
        // stripping it would turn it into `index.docker.io` and stop matching.
        if ($value === self::HUB_KEY) {
            return $value;
        }

        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);

        return strtolower(rtrim(explode('/', $value, 2)[0], '/'));
    }
}
