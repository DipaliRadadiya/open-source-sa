<?php

namespace App\Rules;

use App\Enums\StorageProvider;
use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A folder a "This server" destination may use (#14): allowed, not a link,
 * not somebody else's, and not already another destination's. Asks the disk,
 * changes nothing — the folder is made when the destination is saved.
 */
class LocalBackupPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $folder = app(LocalBackupFolder::class);

        if (($problem = $folder->pathProblem($value)) !== null) {
            $fail($problem)->translate(['roots' => implode(', ', $folder->roots())]);

            return;
        }

        $path = (string) $folder->normalise($value);
        $current = request()->route('storageDestination');
        $currentId = $current instanceof StorageDestination ? $current->getKey() : null;

        // Two destinations in one folder would each prune the other's backups
        // by their own retention.
        $taken = StorageDestination::query()
            ->where('provider', StorageProvider::Local)
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->get()
            ->contains(fn (StorageDestination $other) => rtrim((string) $other->configValue('path'), '/') === $path);

        if ($taken) {
            $fail('storage.local.path_taken')->translate();

            return;
        }

        try {
            $state = $folder->state($path);
        } catch (LocalBackupFolderException $e) {
            $fail($e->key)->translate();

            return;
        }

        match ($state) {
            'parent_missing' => $fail('storage.local.parent_missing')->translate(['parent' => dirname($path)]),
            'foreign' => $fail('storage.local.folder_foreign')->translate(),
            'not_folder' => $fail('storage.local.not_folder')->translate(),
            default => null,
        };
    }
}
