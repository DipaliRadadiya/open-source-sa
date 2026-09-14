<?php

namespace App\Models;

use App\Enums\StorageProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'provider', 'config', 'prefix'])]
class StorageDestination extends Model
{
    protected function casts(): array
    {
        return [
            'provider' => StorageProvider::class,
            // The per-provider credential + address set, encrypted as one
            // value. `encrypted:array` encrypts the whole JSON document, so
            // the secrets inside it are stored in plaintext *within* the
            // ciphertext — do not pre-encrypt anything going in, or it is
            // encrypted twice and every connection fails with a password made
            // of base64.
            'config' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_success' => 'boolean',
        ];
    }

    /**
     * One value out of the provider config.
     *
     * Exists so drivers read `$destination->configValue('host')` instead of
     * `$destination->config['host'] ?? null` at forty call sites, and so a
     * destination whose config failed to decrypt (a restored database, a
     * rotated APP_KEY) answers null rather than throwing a TypeError deep
     * inside a queue worker.
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
     * Merge new values into the config, keeping what was not supplied.
     *
     * This is what makes a partial update mean "rotate the credential I sent,
     * leave the one I didn't" rather than "replace the whole blob and clear
     * everything absent from this request". A null is treated as absent for
     * the same reason — the API's contract is that omission preserves.
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
     * What the panel currently knows about this destination.
     *
     * `never_tested` is deliberately distinct from `failed`: "we have not
     * asked" and "we asked and it said no" are different situations and only
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
     * Called whenever the credentials or the address change: a stored
     * "connected" describes the keys that were tested, not the ones now
     * stored, and a panel that shows a green tick for a key rotated out ten
     * seconds ago is lying about the one thing this field exists to answer.
     */
    public function forgetTestResult(): void
    {
        $this->forceFill([
            'last_tested_at' => null,
            'last_test_success' => null,
            'last_test_error' => null,
        ])->save();
    }
}
