<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;

class CreateStorageDestination
{
    /**
     * @param  array{
     *     name: string,
     *     endpoint: string|null,
     *     region: string,
     *     bucket: string,
     *     prefix: string|null,
     *     access_key: string,
     *     secret_key: string,
     * }  $data
     */
    public function execute(array $data): StorageDestination
    {
        // The encrypted cast on access_key/secret_key runs in the model
        // setter and persists the ciphertext — no extra step needed here.
        return StorageDestination::create($data);
    }
}
