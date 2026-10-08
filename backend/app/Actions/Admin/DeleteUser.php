<?php

namespace App\Actions\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;

class DeleteUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(User $user): void
    {
        $this->activityLogger->log('user.deleted', $user, ['username' => $user->username]);

        // Before the delete nulls user_id on every row they wrote: without
        // the name the log reads as if the system had done it (FS-C22).
        ActivityLog::query()->where('user_id', $user->id)->update(['user_name' => $user->username]);

        $user->tokens()->delete();
        $user->delete();
    }
}
