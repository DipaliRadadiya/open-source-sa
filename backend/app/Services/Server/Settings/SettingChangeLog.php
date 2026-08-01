<?php

namespace App\Services\Server\Settings;

use App\Models\ActivityLog;

/**
 * Who last changed each settings group, for the "last changed by" line on each
 * section.
 *
 * Read from the activity log rather than stored on the group: writes already
 * record `setting.updated` with the group in their properties, so a second
 * record of the same fact could only ever drift from the first.
 *
 * This is deliberately NOT folded into `SettingGroup::read()`. That method is
 * defined as live OS state — it is what `PUT` echoes back, and it is what makes
 * the groups detect-don't-trust. Who touched a form is neither, so it travels
 * beside the settings rather than inside them.
 */
class SettingChangeLog
{
    /**
     * Groups whose writes log a verb of their own instead of `setting.updated`.
     *
     * The reboot schedule arranges for the machine to restart by itself, which
     * earned it a distinct verb — and that verb carries no `group` property, so
     * looking for one would report "never changed" forever.
     */
    private const OWN_VERB = ['reboot_schedule' => 'reboot_schedule_updated'];

    /**
     * Last change per group, keyed by group. Groups never changed are absent
     * rather than null — "we have no record" is not a value.
     *
     * One small indexed query per group. The count is fixed by the number of
     * setting groups, not by how much data exists, so it cannot grow into an
     * N+1.
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
            // Null when the actor has since been deleted — the change still
            // happened, and dropping the whole entry would hide it.
            'user' => $entry->user === null ? null : [
                'id' => $entry->user->id,
                'username' => $entry->user->username,
            ],
            'at' => $entry->created_at?->format('d-m-Y H:i:s'),
            'at_human' => $entry->created_at?->diffForHumans(),
        ];
    }
}
