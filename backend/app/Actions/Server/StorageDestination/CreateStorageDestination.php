<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;
use App\Services\ActivityLogger;

class CreateStorageDestination
{
    public function __construct(private ActivityLogger $activityLogger) {}

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
        $destination = StorageDestination::create([
            'name' => $data['name'],
            'provider' => $data['provider'],
            'prefix' => $data['prefix'] ?? null,
            'config' => $data['config'] ?? [],
        ]);

        // Name and provider only — `config` holds the credentials.
        $this->activityLogger->log('storage_destination.created', $destination, [
            'name' => $destination->name,
            'provider' => $destination->provider,
        ]);

        return $destination;
    }
}
