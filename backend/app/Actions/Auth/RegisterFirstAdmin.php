<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AdministratorRole;
use Illuminate\Support\Facades\Hash;

class RegisterFirstAdmin
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private AdministratorRole $administratorRole,
    ) {}

    /**
     * @param  array{name: string, username: string, password: string}  $data
     */
    public function execute(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'is_admin' => true,
        ]);

        // The first user gets the protected Administrator role (all
        // permissions) — ensure it exists (self-healing if the seeder
        // hasn't run) and attach it. Satisfies the "every user >= 1 role"
        // invariant.
        $user->roles()->attach($this->administratorRole->ensure());

        $this->activityLogger->log('user.registered', $user, ['username' => $user->username], actor: $user);

        return $user;
    }
}
