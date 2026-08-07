<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;

class UpdateStorageDestination
{
    /**
     * Partial update. The FormRequest is responsible for sending only the
     * keys the caller actually wants to change — a missing `access_key` /
     * `secret_key` means "leave the rotated-in credentials alone", which
     * is what a plain rename PATCH should do. The model's encrypted-cast
     * setter takes care of re-encrypting when a new value is supplied.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(StorageDestination $destination, array $data): StorageDestination
    {
        $destination->fill($data)->save();

        // A stored test result describes the credentials and address that
        // were probed. Change either and it describes nothing — keeping it
        // would show "Connected" for a key that was rotated out a moment
        // ago, which is worse than showing nothing at all.
        if ($this->invalidatesTestResult($data)) {
            $destination->forgetTestResult();
        }

        return $destination->refresh();
    }

    /**
     * A rename or a prefix change leaves the connection itself untouched, so
     * they do not throw the result away. Everything that decides *what the
     * panel talks to* does.
     *
     * @param  array<string, mixed>  $data
     */
    private function invalidatesTestResult(array $data): bool
    {
        return array_intersect_key(
            $data,
            array_flip(['access_key', 'secret_key', 'endpoint', 'region', 'bucket']),
        ) !== [];
    }
}
