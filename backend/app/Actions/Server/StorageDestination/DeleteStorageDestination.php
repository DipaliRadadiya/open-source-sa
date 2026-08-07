<?php

namespace App\Actions\Server\StorageDestination;

use App\Models\Application;
use App\Models\StorageDestination;
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
    /**
     * How many application names go in the message before it collapses into
     * a count. A destination shared by forty sites would otherwise produce a
     * multi-kilobyte error string that nobody reads.
     */
    private const NAMED_LIMIT = 5;

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
                    'applications' => $this->list($names->all()),
                ])],
            ]);
        }

        $destination->delete();
    }

    /**
     * @param  array<int, string>  $names
     */
    private function list(array $names): string
    {
        $overflow = count($names) - self::NAMED_LIMIT;

        if ($overflow <= 0) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, self::NAMED_LIMIT))
            .', '.__('storage.delete.and_more', ['count' => $overflow]);
    }
}
