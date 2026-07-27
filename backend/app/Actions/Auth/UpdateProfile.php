<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;

class UpdateProfile
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Update the authenticated user's own profile (name + username).
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): User
    {
        $user->forceFill([
            'name' => $data['name'],
            'username' => $data['username'],
        ])->save();

        $this->activityLogger->log('user.profile_updated', $user, ['username' => $user->username]);

        return $user;
    }
}
