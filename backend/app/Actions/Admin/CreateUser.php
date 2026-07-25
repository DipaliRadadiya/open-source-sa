<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;

class CreateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, username: string, password: string, is_admin: bool, role_ids: array<int, int>}  $data
     */
    public function execute(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'is_admin' => $data['is_admin'],
        ]);

        // Every user must have >= 1 role (enforced in the FormRequest).
        $user->roles()->sync($data['role_ids']);

        $this->activityLogger->log('user.created', $user, ['username' => $user->username]);

        return $user;
    }
}
