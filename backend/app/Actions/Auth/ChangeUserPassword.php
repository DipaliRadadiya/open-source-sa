<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ChangeUserPassword
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(User $user, string $newPassword): void
    {
        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        $user->tokens()->delete();

        $action = Auth::id() === $user->id ? 'user.password_changed' : 'user.password_reset_by_admin';

        $this->activityLogger->log($action, $user, ['username' => $user->username]);
    }
}
