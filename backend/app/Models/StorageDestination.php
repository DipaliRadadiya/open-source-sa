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
            // `encrypted:array` encrypts the whole document: never pre-encrypt values going in,
            // or they are encrypted twice and every connection fails.
            'config' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_success' => 'boolean',
        ];
    }

    /**
     * One value out of the provider config.
     *
     * A config that failed to decrypt (restored database, rotated APP_KEY) answers the default
     * instead of throwing a TypeError inside a queue worker.
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
     * A partial update rotates what was sent and keeps the rest; null counts as absent.
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
     * `never_tested` is deliberately distinct from `failed`: not asked is not the same as refused.
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
     * Called whenever the credentials or address change: a stored "connected" describes the
     * old keys, not the new ones.
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
