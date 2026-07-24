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

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        $this->activityLogger->log('user.logged_in', $user, actor: $user);

        return $user;
    }
}
