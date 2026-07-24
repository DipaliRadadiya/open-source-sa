<?php

namespace App\Services;

use App\Models\Permission;

class PermissionResolver
{
    /**
     * Resolve a list of {level, name, view, manage} items into a sync-ready
     * array keyed by permission ID, with `manage` always implying `view`.
     * Items whose (level, name) pair doesn't match a real permission are
     * silently skipped.
     *
     * @param  array<int, array{level: string, name: string, view: bool, manage: bool}>  $items
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
            if ($id) {
                $sync[$id] = ['view' => $item['view'] || $item['manage'], 'manage' => $item['manage']];
            }
        }

        return $sync;
    }
}
