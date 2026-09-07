<?php

namespace App\Services\Server\Applications;

use App\Models\ActivityLog;
use App\Models\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Who changed an application's `.env`, when, and whether the version they
 * replaced can still be put back.
 *
 * Two sources that answer different questions, and they are not interchangeable:
 *
 * - The **activity log** is the record. It is append-only, keeps the user, and
 *   is never pruned. It is the answer to "who did this".
 * - The **files on disk** are availability. Only {@see
 *   ApplicationEnvironment::KEEP_BACKUPS} survive, and a restore is an offer
 *   this class can only make while the file is still there.
 *
 * They will disagree, always — the log outlives the files by design. So a row
 * whose backup has been pruned is still shown, marked as no longer restorable,
 * rather than dropped. Hiding it would quietly rewrite the history of who
 * touched the file, which is the one thing this screen exists to answer.
 */
class EnvironmentHistory
{
    /** The two actions that change the file. Nothing else belongs in here. */
    private const ACTIONS = ['environment_updated', 'environment_restored'];

    public function __construct(private ApplicationEnvironment $files) {}

    /**
     * Newest first, paginated.
     *
     * The backup listing is read once and turned into a lookup, rather than
     * asked per row: it is a `find` over the application directory, and running
     * it twenty times to render twenty rows would be twenty shell-outs for one
     * screen.
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
            // Ties broken by id: several saves can share a second, and a
            // paginator with an unstable sort can show the same row on two
            // pages and never show another one at all.
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

            // Key names only, exactly as the log stores them. No value from the
            // file has ever been written here and none is read back — the whole
            // premise of this screen is that those are secrets, and a history
            // holding old values would put every rotated password in the panel
            // database for anyone who can read a log.
            'keys' => $properties['keys'] ?? null,

            // Which version a restore put back, when this row is a restore.
            'restored_from' => $properties['restored_from'] ?? null,

            'user' => $row->user ? [
                'id' => $row->user->id,
                'username' => $row->user->username,
            ] : null,
            // Nobody did this one. Said outright rather than left to be
            // inferred from a null user, which is indistinguishable from a row
            // whose user was deleted.
            'is_system' => $row->user_id === null,

            'created_at' => $row->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $row->created_at?->diffForHumans(),

            // The version this change replaced, and whether it is still on
            // disk. `null` backup means a first save — there was nothing to
            // keep, so there is nothing to go back to, which is different from
            // "it was kept and has since been pruned".
            'backup' => $backup,
            'restorable' => $backup !== null && isset($available[$backup]),
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
