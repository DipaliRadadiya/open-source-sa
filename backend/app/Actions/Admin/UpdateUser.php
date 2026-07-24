<?php

namespace App\Actions\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ActivityLogger;

class UpdateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, username: string, role: string}  $data
     */
    public function execute(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'username' => $data['username'],
            'role' => UserRole::from($data['role']),
        ]);

        $this->activityLogger->log('user.updated', $user, ['username' => $user->username]);

        return $user;
    }
}
