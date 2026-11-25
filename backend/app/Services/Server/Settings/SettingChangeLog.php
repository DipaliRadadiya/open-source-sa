<?php

namespace App\Services\Server\Settings;

use App\Models\ActivityLog;

/**
 * Who last changed each settings group, for the "last changed by" line on each section.
 *
 * Read from the activity log, not stored on the group, so there is no second record to drift.
 * Kept out of `SettingGroup::read()`, which is defined as live OS state (what `PUT` echoes back).
 */
class SettingChangeLog
{
    /**
     * Groups whose writes log a verb of their own instead of `setting.updated`.
     * That verb carries no `group` property, so looking for one would report "never changed".
     */
    private const OWN_VERB = ['reboot_schedule' => 'reboot_schedule_updated'];

    /**
     * Last change per group, keyed by group. Groups never changed are absent, not null.
     * One small indexed query per group; the count is fixed by the number of groups.
     *
     * @param  array<int, string>  $groups
     * @return array<string, array<string, mixed>>
     */
    public function forGroups(array $groups): array
    {
        $changes = [];

        foreach ($groups as $group) {
            $entry = $this->latest($group);

            if ($entry !== null) {
                $changes[$group] = $entry;
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latest(string $group): ?array
    {
        $query = ActivityLog::query()
            ->with('user:id,username')
            ->where('type', 'setting');

        if (isset(self::OWN_VERB[$group])) {
            $query->where('action', self::OWN_VERB[$group]);
        } else {
            $query->where('action', 'updated')->where('properties->group', $group);
        }

        $entry = $query->latest('id')->first();

        if ($entry === null) {
            return null;
        }

        return [
            // Null when the actor has since been deleted; the change still happened.
            'user' => $entry->user === null ? null : [
                'id' => $entry->user->id,
                'username' => $entry->user->username,
            ],
            'at' => $entry->created_at?->format('d-m-Y H:i:s'),
            'at_human' => $entry->created_at?->diffForHumans(),
        ];
    }
}
