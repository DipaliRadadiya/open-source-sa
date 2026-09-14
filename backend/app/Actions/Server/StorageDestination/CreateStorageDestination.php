<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;

class CreateStorageDestination
{
    /**
     * @param  array{
     *     name: string,
     *     provider: string,
     *     prefix?: string|null,
     *     config?: array<string, mixed>,
     * }  $data
     */
    public function execute(array $data): StorageDestination
    {
        // The `encrypted:array` cast on `config` runs in the model setter and
        // persists the ciphertext — no extra step needed here, and nothing in
        // `$data` should arrive pre-encrypted.
        return StorageDestination::create([
            'name' => $data['name'],
            'provider' => $data['provider'],
            'prefix' => $data['prefix'] ?? null,
            'config' => $data['config'] ?? [],
        ]);
    }
}
