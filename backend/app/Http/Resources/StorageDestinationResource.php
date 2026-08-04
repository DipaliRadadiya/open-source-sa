<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One S3-compatible storage destination.
 *
 * Secrets are deliberately absent — not masked, not truncated: the
 * `access_key` and `secret_key` columns are encrypted at rest (`encrypted`
 * cast on the model), and the model would *decrypt* them on access. Any
 * reference at all — even `$this->access_key` — would leak the plaintext
 * into the API response. They are write-only: rotation replaces them.
 *
 * `has_credentials` reports whether both secret columns are populated
 * without ever reading their value, so the UI can show a "credentials set"
 * badge and a rotation prompt without ever seeing the secrets themselves.
 */
class StorageDestinationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'driver' => 's3',
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'prefix' => $this->prefix,
            // True when *both* encrypted columns are populated. We read
            // the raw DB value via getRawOriginal() so the encrypted-cast
            // machinery never runs — looking at `$this->access_key` would
            // *decrypt* the column and put the plaintext secret into a
            // string the rest of the request could leak.
            'has_credentials' => $this->hasRawValue('access_key')
                && $this->hasRawValue('secret_key'),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->format('d-m-Y H:i:s'),
            'updated_at_human' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Whether the underlying DB column for an *encrypted* attribute is
     * populated, without ever triggering the model cast and decrypting
     * the value into plaintext.
     */
    private function hasRawValue(string $column): bool
    {
        $raw = $this->getRawOriginal($column);

        return is_string($raw) && $raw !== '';
    }
}
