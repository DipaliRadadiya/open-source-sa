<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A container registry the panel can authenticate to.
 *
 * Server-level, like a storage destination: a credential is set up once and chosen by as many
 * sites as want it, instead of re-typing and rotating a token per site.
 *
 * **The credential never leaves this class as a value anybody asks for.** It is read in one
 * place only — `RegistryAuth`, which writes it into a root-owned `config.json` for the length
 * of one pull. `RegistryResource` reports only whether it is populated.
 */
#[Fillable(['name', 'registry', 'username', 'config'])]
class Registry extends Model
{
    /**
     * Docker's own name for the Hub, and what `config.json` must be keyed on.
     *
     * A `config.json` keyed `docker.io` is ignored for a Hub pull: the daemon looks up the legacy
     * v1 index URL.
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
     * Read to answer "what breaks if I delete this" before the UI offers the delete button.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * One value out of the credential set.
     *
     * Callers do not repeat `$config['x'] ?? null`, and a row whose config failed to decrypt (a
     * database restored under a different `APP_KEY`) answers null instead of throwing in a worker.
     */
    public function configValue(string $key, mixed $default = null): mixed
    {
        $config = $this->decodedConfig();

        if (! is_array($config)) {
            return $default;
        }

        return $config[$key] ?? $default;
    }

    /**
     * The decrypted credential set, or null when it cannot be decrypted.
     *
     * The `try` is the point: reading an `encrypted:array` attribute whose ciphertext does not
     * belong to this `APP_KEY` throws `DecryptException` rather than returning null, and a database
     * restored under a different key would turn every registry read into a 500.
     *
     * Null rather than an empty array, so `hasCredentials()` reports false and a
     * caller cannot mistake "we cannot read it" for "there is nothing here".
     *
     * @return array<string, mixed>|null
     */
    private function decodedConfig(): ?array
    {
        try {
            $config = $this->config;
        } catch (DecryptException) {
            return null;
        }

        return is_array($config) ? $config : null;
    }

    /**
     * Merge new values in, keeping what was not supplied.
     *
     * A PATCH means "rotate the token I sent, leave the rest". Null counts as absent: a form with
     * an empty password box must not wipe a working credential on save.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeConfig(array $values): void
    {
        $this->config = array_merge(
            $this->decodedConfig() ?? [],
            array_filter($values, fn ($value): bool => $value !== null),
        );
    }

    /**
     * Is there a token stored at all?
     *
     * Only emptiness is reported, never the value, so the UI can show "credentials set" without
     * the secret going near a response.
     */
    public function hasCredentials(): bool
    {
        return trim((string) $this->configValue('token', '')) !== '';
    }

    /**
     * What the panel currently knows about this registry.
     *
     * `never_tested` is distinct from `failed`: "we have not asked" and "we asked and it refused"
     * are different situations, and only one is the user's problem to fix.
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
     * Called whenever the credential or the address changes: a stored "connected" describes the
     * token that was tested, not the one now stored.
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
     * Needed by the login probe, which must be given no argument at all for Hub (`docker login
     * docker.io` is accepted, but plain `docker login` is the daemon's own default path).
     */
    public function isDockerHub(): bool
    {
        return in_array($this->normalisedHost(), self::HUB_HOSTS, true);
    }

    /**
     * The host, with the scheme and any path a user pasted taken back off.
     *
     * People paste `https://ghcr.io` and `ghcr.io/myorg`. Neither is a config.json key, and a
     * mismatched key is silently ignored (the pull behaves as if no credential were stored). This
     * is normalisation, not validation: the form still refuses what it cannot interpret.
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
