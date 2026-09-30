<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\Application;
use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Support\NameList;
use Illuminate\Validation\ValidationException;

/**
 * Delete a storage destination, refusing while any backup target still
 * points at it.
 *
 * The foreign key is `restrictOnDelete`, so the database would stop this
 * anyway — as a driver exception surfacing to the user as a 500 with no
 * indication of what went wrong or what to do about it. The guard turns the
 * same refusal into a 422 that names the sites, so the operator can go and
 * repoint them instead of guessing which of forty applications is holding
 * the destination.
 */
class DeleteStorageDestination
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(StorageDestination $destination): void
    {
        $names = Application::query()
            ->whereHas('backupTarget', fn ($query) => $query->where('storage_destination_id', $destination->getKey()))
            ->orderBy('name')
            ->pluck('name');

        if ($names->isNotEmpty()) {
            throw ValidationException::withMessages([
                'storage_destination' => [__('storage.delete.in_use', [
                    'name' => $destination->name,
                    'applications' => NameList::summarise($names->all(), 'storage.delete.and_more'),
                ])],
            ]);
        }

        // Backups whose archives are still in this destination. The database
        // already refuses (`backups.storage_destination_id` is restrictOnDelete),
        // but as an integrity error the user saw as a 500. Deleting them here
        // instead would be the wrong kindness: an archive is somebody's only
        // copy, and it goes when they delete the backup, not as a side effect.
        $held = Backup::query()->where('storage_destination_id', $destination->getKey())->count();

        if ($held > 0) {
            throw ValidationException::withMessages([
                'storage_destination' => [__('storage.delete.holds_backups', [
                    'name' => $destination->name,
                    'count' => $held,
                ])],
            ]);
        }

        $destination->delete();

        $this->activityLogger->log('storage_destination.deleted', null, ['name' => $destination->name]);
    }
}
