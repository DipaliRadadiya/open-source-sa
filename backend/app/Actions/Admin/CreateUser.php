<?php

namespace App\Actions\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;

class CreateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, username: string, password: string, role: string}  $data
     */
    public function execute(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'role' => UserRole::from($data['role']),
        ]);

        $this->activityLogger->log('user.created', $user, ['username' => $user->username, 'role' => $user->role->value]);

        return $user;
    }
}
