<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\ActivityLogger;

class DeleteUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(User $user): void
    {
        $this->activityLogger->log('user.deleted', $user, ['username' => $user->username]);

        $user->tokens()->delete();
        $user->delete();
    }
}
