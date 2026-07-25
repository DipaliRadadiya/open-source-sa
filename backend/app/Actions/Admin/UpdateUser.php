<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;

class UpdateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, username: string, is_admin: bool}  $data
     */
    public function execute(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'username' => $data['username'],
            'is_admin' => $data['is_admin'],
        ]);

        $this->activityLogger->log('user.updated', $user, ['username' => $user->username]);

        return $user;
    }
}
