<?php

namespace App\Services\Integrations;

use App\Enums\StorageProvider;
use App\Models\GitAccount;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * An integration that is already here, found by its credential.
 *
 * The central panel connects a git account or a storage destination through
 * the same endpoints as the panel's own screens, and then clones or backs up
 * with it. Sent twice — a retry after a timeout, a second clone — it used to
 * leave two records for one account, or answer 422 for a name and leave the
 * caller to work out which record was meant. Dipali, 2026-10-28: exactly the
 * same credential returns the record that holds it; any difference at all (a
 * new token, another bucket) is a new record, and an existing one is never
 * changed.
 *
 * Credentials are encrypted at rest, so the database cannot be asked; the
 * records of the same provider are decrypted and compared here. A server has
 * a handful of them.
 */
class DuplicateIntegration
{
    /** Config keys that are instructions for this request, not part of the credential. */
    private const TRANSIENT_KEYS = ['trust_new_certificate'];

    public function __construct(private StorageDriverFactory $drivers) {}

    /**
     * The git account holding exactly this provider, host, workspace and token.
     *
     * @param  array<string, mixed>  $input
     */
    public function gitAccount(array $input): ?GitAccount
    {
        $token = $input['token'] ?? null;

        if (! is_string($token) || $token === '' || ! is_string($input['provider'] ?? null)) {
            return null;
        }

        return GitAccount::query()
            ->where('provider', $input['provider'])
            ->orderBy('id')
            ->get()
            ->first(fn (GitAccount $account) => $this->readable(fn () => hash_equals((string) $account->token, $token))
                && $this->host($account->provider, $account->host) === $this->host($input['provider'], $input['host'] ?? null)
                && $this->same($account->workspace, $input['workspace'] ?? null));
    }

    /**
     * The storage destination with exactly this provider, prefix and
     * credential — every config field the provider's form asks for.
     *
     * @param  array<string, mixed>  $input
     */
    public function storageDestination(array $input): ?StorageDestination
    {
        $provider = StorageProvider::tryFrom((string) ($input['provider'] ?? ''));
        $config = $input['config'] ?? null;

        if ($provider === null || ! is_array($config)) {
            return null;
        }

        $keys = $this->configKeys($provider);

        return StorageDestination::query()
            ->where('provider', $provider)
            ->orderBy('id')
            ->get()
            ->first(fn (StorageDestination $destination) => $this->readable(function () use ($destination, $provider, $config, $input, $keys) {
                if (! $this->same(trim((string) $destination->prefix, '/'), trim((string) ($input['prefix'] ?? ''), '/'))) {
                    return false;
                }

                foreach ($keys as $key) {
                    if (! $this->same($this->value($provider, $key, $destination->configValue($key)), $this->value($provider, $key, $config[$key] ?? null))) {
                        return false;
                    }
                }

                return true;
            }));
    }

    /**
     * A record whose credential cannot be decrypted (the app key changed) is
     * not the same as anything — and must not turn a create into a 500.
     */
    private function readable(callable $compare): bool
    {
        try {
            return (bool) $compare();
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * The config fields this provider's form takes, from its own rules — so a
     * field added to a provider later is compared without anyone remembering
     * to add it here.
     *
     * @return list<string>
     */
    private function configKeys(StorageProvider $provider): array
    {
        $keys = [];

        foreach (array_keys($this->drivers->forProvider($provider)->rules()) as $rule) {
            if (preg_match('/^config\.([a-z0-9_]+)$/i', $rule, $m) && ! in_array($m[1], self::TRANSIENT_KEYS, true)) {
                $keys[] = $m[1];
            }
        }

        return $keys;
    }

    private function value(StorageProvider $provider, string $key, mixed $value): mixed
    {
        // One folder written two ways is still one folder.
        if ($provider === StorageProvider::Local && $key === 'path' && is_string($value)) {
            return app(LocalBackupFolder::class)->normalise($value) ?? $value;
        }

        return $value;
    }

    /**
     * Equal as a person would mean it: empty and missing are the same, so are
     * `true` and `1`, and surrounding spaces do not count. Strings are
     * compared in constant time — some of them are secrets.
     */
    private function same(mixed $a, mixed $b): bool
    {
        return hash_equals($this->normal($a), $this->normal($b));
    }

    private function normal(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => (string) json_encode($value),
            default => trim((string) $value),
        };
    }

    /** gitlab.com written as nothing, as a host, or as a URL is one host. */
    private function host(string $provider, mixed $host): string
    {
        $host = strtolower(trim((string) $host));
        $host = rtrim(preg_replace('#^https?://#', '', $host) ?? $host, '/');

        return $host === '' && $provider === 'gitlab' ? 'gitlab.com' : $host;
    }
}
