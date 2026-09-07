<?php

namespace App\Services\Server\Applications;

use App\Models\ActivityLog;
use App\Models\Application;

/**
 * What one change to a `.env` actually did, variable by variable.
 *
 * The values come off the **backup files**, never out of the activity log.
 * That is not squeamishness: `activity_logs` is append-only, never pruned, and
 * ships inside every database backup, and its rows are rendered by an
 * admin-wide screen gated on `access-admin` rather than on this application's
 * `app_environment`. Writing values there would put every password this panel
 * has ever set into the database permanently, readable by a different audience
 * than the one granted access to the file. The backups already hold the exact
 * contents, 0600, owned by the site's own user, and they expire.
 *
 * Reading them costs nothing extra and leaks nothing new.
 */
class EnvironmentDiff
{
    public function __construct(private ApplicationEnvironment $files) {}

    /**
     * The before and after of one logged change.
     *
     * A row's `backup` names the file holding the state **before** that change.
     * The state **after** it is therefore the backup taken by the next change,
     * or the live file when nothing has happened since. Pruning removes the
     * oldest first, so a row whose own backup survives has a successor that
     * survives too.
     *
     * @return array{
     *     available: bool,
     *     changes: array<int, array{key: string, before: string|null, after: string|null, status: string}>
     * }
     */
    public function for(Application $application, ActivityLog $row): array
    {
        $before = $this->stateBefore($application, $row);
        $after = $this->stateAfter($application, $row);

        // Null means the file that held it is gone. Rendering that as an empty
        // diff would say "this change touched nothing", which is a different
        // and false statement.
        if ($before === null || $after === null) {
            return ['available' => false, 'changes' => []];
        }

        return ['available' => true, 'changes' => $this->compare($before, $after)];
    }

    private function stateBefore(Application $application, ActivityLog $row): ?string
    {
        $backup = $row->properties['backup'] ?? null;

        // No backup on a first save: there was no file, so the state before it
        // was genuinely empty. Distinct from a pruned one, which is unknown.
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
            // Nothing has changed the file since, so what is on disk now is
            // exactly what this change left behind.
            return $this->files->exists($application) ? $this->files->read($application) : '';
        }

        $backup = $next->properties['backup'] ?? null;

        // The next change took no backup, meaning it found no file to copy —
        // the file was removed outside the panel between the two. Its state
        // cannot be reconstructed, and guessing "empty" would report every
        // variable as deleted by a change that deleted nothing.
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
     * @return array<int, array{key: string, before: string|null, after: string|null, status: string}>
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

        return $changes;
    }

    /**
     * Key => value, from the raw text.
     *
     * Deliberately the same shape of parse as the one that decides which key
     * names go into the activity log: if these two disagreed, a change could be
     * listed in the log and absent from its own diff, or the reverse. Comments
     * and blank lines are skipped — this answers "which variables changed", and
     * the file itself is one click away for anyone who wants the rest.
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
