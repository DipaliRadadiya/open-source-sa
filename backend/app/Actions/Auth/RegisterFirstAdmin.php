<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AdministratorRole;
use App\Services\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
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
     * One transaction: registration closes as soon as a user row exists
     * (RegisterRequest::authorize is `User::doesntExist()`), so a half-done one could not be retried.
     *
     * @param  array{name: string, username: string, password: string}  $data
     */
    public function execute(array $data): User
    {
        // One registration at a time, and "no user yet" re-checked under the lock: authorize()
        // runs before it, so two simultaneous sign-ups would both become administrators.
        return Cache::lock('register-first-admin', 30)->block(10, fn (): User => DB::transaction(function () use ($data): User {
            if (User::query()->where('is_system', false)->exists()) {
                throw new AuthorizationException(__('auth.registration_closed'));
            }

            // Write the catalog first: the Administrator role is "every permission", so if the
            // seeder never ran the first admin would get a role that grants nothing. Always run
            // (idempotent upsert), not only when empty.
            $this->permissionCatalog->sync();

            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'is_admin' => true,
            ]);

            // The protected Administrator role (every user must have at least one role).
            $user->roles()->attach($this->administratorRole->ensure());

            $this->activityLogger->log('user.registered', $user, ['username' => $user->username], actor: $user);

            return $user;
        }));
    }
}
