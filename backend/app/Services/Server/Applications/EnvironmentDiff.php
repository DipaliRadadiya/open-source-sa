<?php

namespace App\Services\Server\Applications;

use App\Models\ActivityLog;
use App\Models\Application;

/**
 * What one change to a `.env` actually did, variable by variable.
 *
 * The values come off the backup files, never out of the activity log. `activity_logs` is
 * append-only, never pruned, ships inside every database backup, and is shown on an admin-wide
 * screen gated on `access-admin`, not on this application's `app_environment`. Logging values
 * there would keep every password permanently, readable by a wider audience. The backups are
 * 0600, owned by the site's user, and expire.
 */
class EnvironmentDiff
{
    public function __construct(private ApplicationEnvironment $files) {}

    /**
     * The before and after of one logged change.
     *
     * A row's `backup` holds the state before that change; the state after it is the next
     * change's backup, or the live file if nothing changed since. Pruning removes the oldest
     * first, so a row whose backup survives has a surviving successor.
     *
     * @return array{
     *     available: bool,
     *     changes: array<int, array{key: string, before: string|null, after: string|null, status: string, secret: bool}>
     * }
     */
    public function for(Application $application, ActivityLog $row): array
    {
        $before = $this->stateBefore($application, $row);
        $after = $this->stateAfter($application, $row);

        // Null means the file is gone; an empty diff would falsely say "this change touched nothing".
        if ($before === null || $after === null) {
            return ['available' => false, 'changes' => []];
        }

        return ['available' => true, 'changes' => $this->compare($before, $after)];
    }

    private function stateBefore(Application $application, ActivityLog $row): ?string
    {
        $backup = $row->properties['backup'] ?? null;

        // No backup on a first save means the state before was empty; a pruned one is unknown.
        if ($backup === null) {
            return $this->wasFirstSave($application, $row) ? '' : null;
        }

        return $this->files->readBackup($application, $backup);
    }

    private function stateAfter(Application $application, ActivityLog $row): ?string
    {
        $next = ActivityLog::query()
            ->where('type', 'application')
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->whereIn('action', ['environment_updated', 'environment_restored'])
            ->where('id', '>', $row->id)
            ->orderBy('id')
            ->first();

        if ($next === null) {
            // Nothing changed the file since: the disk holds what this change left.
            return $this->files->exists($application) ? $this->files->read($application) : '';
        }

        $backup = $next->properties['backup'] ?? null;

        // No backup means the file was removed outside the panel in between. The state is unknown;
        // guessing "empty" would report every variable as deleted.
        return $backup === null ? null : $this->files->readBackup($application, $backup);
    }

    private function wasFirstSave(Application $application, ActivityLog $row): bool
    {
        return ! ActivityLog::query()
            ->where('type', 'application')
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->whereIn('action', ['environment_updated', 'environment_restored'])
            ->where('id', '<', $row->id)
            ->exists();
    }

    /**
     * @return array<int, array{key: string, before: string|null, after: string|null, status: string, secret: bool}>
     */
    private function compare(string $before, string $after): array
    {
        $old = $this->parse($before);
        $new = $this->parse($after);

        $changes = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old)) {
                $changes[] = ['key' => $key, 'before' => null, 'after' => $value, 'status' => 'added'];
            } elseif ($old[$key] !== $value) {
                $changes[] = ['key' => $key, 'before' => $old[$key], 'after' => $value, 'status' => 'changed'];
            }
        }

        foreach ($old as $key => $value) {
            if (! array_key_exists($key, $new)) {
                $changes[] = ['key' => $key, 'before' => $value, 'after' => null, 'status' => 'removed'];
            }
        }

        usort($changes, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        // Secrets: show which one changed, never its old or new value.
        return array_map(function (array $change): array {
            $secret = EnvironmentInspector::isSecret($change['key']);

            return $secret
                ? ['key' => $change['key'], 'before' => null, 'after' => null, 'status' => $change['status'], 'secret' => true]
                : $change + ['secret' => false];
        }, $changes);
    }

    /**
     * Key => value, from the raw text.
     *
     * Parses like the code that picks key names for the activity log, so the log and the diff
     * cannot disagree. Comments and blank lines are skipped.
     *
     * @return array<string, string>
     */
    private function parse(string $raw): array
    {
        $map = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m) === 1) {
                $map[$m[1]] = trim($m[2]);
            }
        }

        return $map;
    }
}
