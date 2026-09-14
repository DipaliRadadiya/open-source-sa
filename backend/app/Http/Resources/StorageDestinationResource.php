<?php

namespace App\Http\Resources;

use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One storage destination, of whatever provider.
 *
 * Secrets are deliberately absent — not masked, not truncated. The whole
 * `config` column is encrypted at rest (`encrypted:array` cast), and the model
 * would *decrypt* it on access. So this resource never serialises `config`;
 * it asks the provider's driver for the non-secret subset instead, and the
 * driver is the only thing that knows which of its keys are credentials.
 *
 * `has_credentials` reports whether the provider's secrets are populated
 * without putting their values anywhere near the response, so the UI can show
 * a "credentials set" badge and a rotation prompt without ever seeing them.
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

            // The real provider, read from the column. This used to be a
            // hardcoded `'s3'` and the frontend inferred the truth by
            // matching the endpoint hostname — a guess that was wrong for a
            // self-hosted MinIO and meaningless for an FTP host.
            'provider' => $this->provider->value,
            'provider_title' => $this->provider->title(),

            // Addressing detail only: bucket and region for S3, host and port
            // for FTP/SFTP. Never a credential — see the driver's
            // `secretKeys()` for what is withheld.
            'config' => $driver->publicConfig($this->resource),

            'prefix' => $this->prefix,

            // True when every secret this provider needs is populated. The
            // config is read once, already decrypted by the cast, and only
            // its *emptiness* is reported — no value reaches the array.
            'has_credentials' => $this->hasCredentials($driver->secretKeys()),

            // The last connection probe, so "this destination works" survives
            // a page reload. `last_test_success` is null when the panel has
            // never asked — a different answer from "asked and it failed",
            // which is why this is not a plain boolean.
            'last_tested_at' => $this->last_tested_at?->format('d-m-Y H:i:s'),
            // Show the age, not just the verdict: a destination tested forty
            // days ago is not a destination known to work today.
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
     * SFTP is the reason this is "any of", not "all of": password and private
     * key are alternatives, and a key-authenticated destination has no
     * password without being incomplete.
     *
     * @param  list<string>  $keys
     */
    private function hasCredentials(array $keys): bool
    {
        foreach ($keys as $key) {
            if (filled($this->resource->configValue($key))) {
                return true;
            }
        }

        return false;
    }
}
