<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Permission;

class PermissionResolver
{
    /**
     * Resolve a list of permission items into a sync-ready array keyed by
     * permission ID, with `manage` always implying `view`. An item carries
     * either `access` (none|view|manage) or the older `view`/`manage` pair.
     * Items whose (level, name) pair doesn't match a real permission are
     * silently skipped.
     *
     * @param  array<int, array{level: string, name: string, access?: string, view?: bool, manage?: bool}>  $items
     * @return array<int, array{view: bool, manage: bool}>
     */
    public function resolve(array $items): array
    {
        $permissionIds = Permission::query()
            ->get(['id', 'level', 'name'])
            ->mapWithKeys(fn (Permission $permission) => [$permission->level.'|'.$permission->name => $permission->id]);

        $sync = [];
        foreach ($items as $item) {
            $id = $permissionIds->get($item['level'].'|'.$item['name']);

            if (! $id) {
                continue;
            }

            // A form that sends the three-way `access` wins: it is the only
            // one of the two shapes that cannot express an invalid grant, so
            // there is nothing left to reconcile.
            $sync[$id] = isset($item['access'])
                ? AccessLevel::from($item['access'])->toGrant()
                : ['view' => ($item['view'] ?? false) || ($item['manage'] ?? false), 'manage' => $item['manage'] ?? false];
        }

        return $sync;
    }
}
