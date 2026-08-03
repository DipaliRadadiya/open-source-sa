<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;
use Illuminate\Validation\ValidationException;

/**
 * Delete a storage destination, or refuse if any backup target still
 * points at it. A target without its destination will fail the *next*
 * scheduled run; removing the destination up front turns that into a
 * noisy 422 the operator can act on, rather than a silent broken run
 * for an unsuspecting tenant.
 */
class DeleteStorageDestination
{
    public function execute(StorageDestination $destination): void
    {
        $inUse = $destination->backupTargets()->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'name' => [__('storage.test.in_use', ['name' => $destination->name])],
            ]);
        }

        $destination->delete();
    }
}
