<?php

namespace App\Actions\Admin;

use App\Models\Role;
use App\Services\ActivityLogger;
use App\Support\NameList;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(Role $role): void
    {
        // Every user has at least one role — creating a user and editing a
        // user's roles both refuse an empty list. Deleting the role was the
        // one way round that: the pivot rows cascade, so a user whose only
        // role this was kept their login and lost every permission, with
        // nothing on screen saying why (found 2026-10-01). Refused while any
        // such user exists, naming them, so they are given another role
        // first. Users who hold other roles too just lose this one, which is
        // what the delete dialog tells the admin.
        $stranded = $role->users()
            ->has('roles', '=', 1)
            ->orderBy('username')
            ->pluck('username');

        if ($stranded->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role' => [__('role.last_role_of_users', [
                    'name' => $role->name,
                    'users' => NameList::summarise($stranded->all(), 'role.and_more'),
                ])],
            ]);
        }

        $name = $role->name;

        // System roles are blocked from deletion upstream.
        $role->delete();

        $this->activityLogger->log('role.deleted', null, ['name' => $name]);
    }
}
