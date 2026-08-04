<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\StorageDestination;

/**
 * Delete a storage destination.
 *
 * P2 (backup targets) must add an in-use guard here: a target whose
 * destination has vanished will fail the *next* scheduled run, so
 * refusing the delete turns that into a noisy 422 the operator can act
 * on rather than a silent broken run for an unsuspecting tenant. The
 * `storage.test.in_use` message is already translated in all 8 locales
 * and is waiting for that guard.
 */
class DeleteStorageDestination
{
    public function execute(StorageDestination $destination): void
    {
        $destination->delete();
    }
}
