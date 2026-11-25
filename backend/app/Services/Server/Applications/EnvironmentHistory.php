<?php

namespace App\Services\Server\Applications;

use App\Models\ActivityLog;
use App\Models\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Who changed an application's `.env`, when, and whether the version they replaced can still be
 * put back.
 *
 * - The **activity log** is the record: append-only, keeps the user, never pruned.
 * - The **files on disk** are availability: only {@see ApplicationEnvironment::KEEP_BACKUPS}
 *   survive, and a restore can only be offered while the file is still there.
 *
 * The log outlives the files, so a row whose backup was pruned is still shown, marked as no longer
 * restorable. Dropping it would rewrite the history of who touched the file.
 */
class EnvironmentHistory
{
    /** The two actions that change the file. Nothing else belongs in here. */
    private const ACTIONS = ['environment_updated', 'environment_restored'];

    public function __construct(private ApplicationEnvironment $files) {}

    /**
     * Newest first, paginated.
     *
     * The backup listing is read once and turned into a lookup: it is a `find` over the
     * application directory, so asking per row would be one shell-out per row.
     */
    public function for(Application $application, int $perPage = 20): LengthAwarePaginator
    {
        $available = $this->availableBackups($application);

        $paginator = ActivityLog::query()
            ->with('user:id,username')
            ->where('type', 'application')
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->whereIn('action', self::ACTIONS)
            ->latest('created_at')
            // Ties broken by id: saves can share a second, and an unstable sort can repeat or skip
            // rows across pages.
            ->latest('id')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (ActivityLog $row) => $this->present($row, $available)),
        );

        return $paginator;
    }

    /**
     * @param  array<string, string>  $available  backup name => created_at
     * @return array<string, mixed>
     */
    private function present(ActivityLog $row, array $available): array
    {
        $properties = $row->properties ?? [];
        $backup = $properties['backup'] ?? null;

        return [
            'id' => $row->id,
            'action' => $row->action,

            // Key names only, as the log stores them. Never store or read values from the file:
            // they are secrets, and old values would keep every rotated password in the database.
            'keys' => $properties['keys'] ?? null,

            // Which version a restore put back, when this row is a restore.
            'restored_from' => $properties['restored_from'] ?? null,

            'user' => $row->user ? [
                'id' => $row->user->id,
                'username' => $row->user->username,
            ] : null,
            // Stated outright: a null user is indistinguishable from a deleted user.
            'is_system' => $row->user_id === null,

            'created_at' => $row->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $row->created_at?->diffForHumans(),

            // The version this change replaced, and whether it is still on disk. A `null` backup
            // means a first save (nothing to go back to), not "kept and since pruned".
            'backup' => $backup,
            'restorable' => $backup !== null && isset($available[$backup]),
            // False when the user saved without a copy: then a null `backup` is a copy never
            // taken, not a first save. Older rows always kept one.
            'safety_copy' => ($properties['safety_copy'] ?? true) !== false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function availableBackups(Application $application): array
    {
        $names = [];

        foreach ($this->files->backups($application) as $backup) {
            $names[$backup['name']] = $backup['created_at'];
        }

        return $names;
    }
}
