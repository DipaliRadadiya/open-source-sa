<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AdministratorRole;
use App\Services\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterFirstAdmin
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private AdministratorRole $administratorRole,
        private PermissionCatalog $permissionCatalog,
    ) {}

    /**
     * Create the first user, as an administrator holding every permission.
     *
     * The whole thing is one transaction because registration closes the
     * instant a user row exists (RegisterRequest::authorize is
     * `User::doesntExist()`). A half-completed registration would therefore
     * leave a broken admin behind a permanently closed door, with no way to
     * retry and no way to fix it from the UI.
     *
     * @param  array{name: string, username: string, password: string}  $data
     */
    public function execute(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            // Write the catalog first: the Administrator role is defined as
            // "every permission", so on an install where the seeder never ran
            // that set is empty and the first admin ends up with a role that
            // grants nothing — an empty sidebar and a 403 on every feature,
            // while the admin area still works, which reads as a successful
            // install. Unconditional rather than only-when-empty: it is the
            // same idempotent upsert the deploy and the sync button already
            // run, and one path that always executes beats two where the rare
            // one is never exercised.
            $this->permissionCatalog->sync();

            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'is_admin' => true,
            ]);

            // The protected Administrator role, now that there is a catalog
            // for it to hold. Satisfies the "every user >= 1 role" invariant.
            $user->roles()->attach($this->administratorRole->ensure());

            $this->activityLogger->log('user.registered', $user, ['username' => $user->username], actor: $user);

            return $user;
        });
    }
}
