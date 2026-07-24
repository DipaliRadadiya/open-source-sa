<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;

class RegisterFirstAdmin
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, username: string, password: string}  $data
     */
    public function execute(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'role' => UserRole::Admin,
        ]);

        $this->activityLogger->log('user.registered', $user, ['username' => $user->username], actor: $user);

        return $user;
    }
}
