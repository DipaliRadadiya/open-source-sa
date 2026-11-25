<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;
use App\Services\ActivityLogger;

class UpdateStorageDestination
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Partial update. The FormRequest sends only the keys the caller wants to change; a missing
     * credential means "leave the stored value alone". The model's `encrypted:array` setter
     * re-encrypts the whole config document when any part changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(StorageDestination $destination, array $data): StorageDestination
    {
        $config = $data['config'] ?? [];

        // Trusting a renewed FTPS certificate forgets the pinned one; the next connection records
        // whatever the server presents now. A flag, never stored.
        $trusting = is_array($config) && (bool) ($config['trust_new_certificate'] ?? false);

        if ($trusting) {
            $destination->config = array_diff_key((array) $destination->config, ['tls_fingerprint' => true]);
        }

        if (is_array($config)) {
            unset($config['trust_new_certificate']);
        }

        // Merged, never replaced: a PATCH with only `config.host` must not clear the stored password.
        if (is_array($config) && $config !== []) {
            $destination->mergeConfig($config);
        }

        $destination->fill(array_intersect_key($data, array_flip(['name', 'prefix'])))->save();

        // A stored test result describes the credentials and address that were probed; once either
        // changes, keeping it would show "Connected" for a key that was rotated out.
        if ($trusting || $this->invalidatesTestResult($config)) {
            $destination->forgetTestResult();
        }

        $this->activityLogger->log('storage_destination.updated', $destination, ['name' => $destination->name]);

        return $destination->refresh();
    }

    /**
     * A rename or prefix change leaves the connection untouched, so the result stays; any config
     * key (what the panel talks to) invalidates it.
     *
     * The fingerprints are the exception: a successful probe records them, so counting them as a
     * change would make every probe invalidate its own result.
     *
     * @param  array<string, mixed>  $config
     */
    private function invalidatesTestResult(mixed $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        return array_diff_key($config, array_flip(['host_fingerprint', 'tls_fingerprint'])) !== [];
    }
}
