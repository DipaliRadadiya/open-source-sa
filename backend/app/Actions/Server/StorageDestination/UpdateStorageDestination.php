<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;
use App\Services\ActivityLogger;

class UpdateStorageDestination
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Partial update. The FormRequest is responsible for sending only the
     * keys the caller actually wants to change — a missing credential means
     * "leave the rotated-in value alone", which is what a plain rename PATCH
     * should do. The model's `encrypted:array` setter re-encrypts the whole
     * config document when any part of it changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(StorageDestination $destination, array $data): StorageDestination
    {
        $config = $data['config'] ?? [];

        // Merged, never replaced. A PATCH carrying only `config.host` must
        // not clear the password stored beside it — assigning the incoming
        // array wholesale would do exactly that, and the destination would
        // keep working until the next backup ran.
        if (is_array($config) && $config !== []) {
            $destination->mergeConfig($config);
        }

        $destination->fill(array_intersect_key($data, array_flip(['name', 'prefix'])))->save();

        // A stored test result describes the credentials and address that
        // were probed. Change either and it describes nothing — keeping it
        // would show "Connected" for a key that was rotated out a moment
        // ago, which is worse than showing nothing at all.
        if ($this->invalidatesTestResult($config)) {
            $destination->forgetTestResult();
        }

        $this->activityLogger->log('storage_destination.updated', $destination, ['name' => $destination->name]);

        return $destination->refresh();
    }

    /**
     * A rename or a prefix change leaves the connection itself untouched, so
     * they do not throw the result away. Everything that decides *what the
     * panel talks to* does.
     *
     * This is now "any config key at all" rather than the old hardcoded list
     * of five S3 columns: that list could only ever be right for one
     * provider, and a host or password change slipping past it would leave a
     * green "Connected" badge describing a destination that no longer exists.
     *
     * `host_fingerprint` is the one exception — it is *recorded by* a
     * successful probe rather than supplied by a user, so treating it as a
     * change would make every SFTP probe invalidate its own result.
     *
     * @param  array<string, mixed>  $config
     */
    private function invalidatesTestResult(mixed $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        return array_diff_key($config, array_flip(['host_fingerprint'])) !== [];
    }
}
