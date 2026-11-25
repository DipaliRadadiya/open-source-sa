<?php

namespace App\Http\Resources;

use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One storage destination, of whatever provider.
 *
 * Secrets are absent, not masked: the model decrypts the `config` column on access, so this
 * resource never serialises it. The provider's driver returns the non-secret subset, as only it
 * knows which keys are credentials. `has_credentials` reports presence only, never values.
 */
class StorageDestinationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $driver = app(StorageDriverFactory::class)->for($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,

            'provider' => $this->provider->value,
            'provider_title' => $this->provider->title(),

            // Addressing detail only (bucket/region, host/port); never a credential, see `secretKeys()`.
            'config' => $driver->publicConfig($this->resource),

            'prefix' => $this->prefix,

            // True when every secret this provider needs is populated; only emptiness is reported.
            'has_credentials' => $this->hasCredentials($driver->secretKeys()),

            // The last connection probe. `last_test_success` is null when never tested, which
            // differs from "tested and failed".
            'last_tested_at' => $this->last_tested_at?->format('d-m-Y H:i:s'),
            // Show the age too: an old successful test says little about today.
            'last_tested_at_human' => $this->last_tested_at?->diffForHumans(),
            'last_test_success' => $this->last_test_success,
            // Stable category — `invalid_credentials`, `unreachable`,
            // `host_key_mismatch`, `invalid_private_key`, `mismatch`.
            'last_test_error' => $this->last_test_error,
            'status' => $this->testStatus(),
            'status_title' => __('storage.status.'.$this->testStatus()),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->format('d-m-Y H:i:s'),
            'updated_at_human' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Whether every secret the provider requires is present.
     *
     * "Any of", not "all of": SFTP password and private key are alternatives.
     *
     * @param  list<string>  $keys
     */
    private function hasCredentials(array $keys): bool
    {
        // A provider with no secrets at all ("This server") is never missing one.
        if ($keys === []) {
            return true;
        }

        foreach ($keys as $key) {
            if (filled($this->resource->configValue($key))) {
                return true;
            }
        }

        return false;
    }
}
