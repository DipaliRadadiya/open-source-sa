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

        return $destination->refresh();
    }
}
