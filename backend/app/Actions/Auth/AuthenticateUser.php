<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;

class AuthenticateUser
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(string $username, string $password): ?User
    {
        $user = User::query()->where('username', $username)->first();

        // A machine account has a random password nobody holds, but rejecting
        // it before the hash check makes that a rule rather than a side effect.
        if (! $user || $user->isSystem() || ! Hash::check($password, $user->password)) {
            return null;
        }

        $this->activityLogger->log('user.logged_in', $user, actor: $user);

        return $user;
    }
}
