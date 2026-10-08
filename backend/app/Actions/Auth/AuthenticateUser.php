<?php

namespace App\Actions\Auth;

use App\Models\ActivityLog;
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
            $this->recordFailure($user);

            return null;
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();

        $this->activityLogger->log('user.logged_in', $user, ['ip' => (string) request()->ip()], actor: $user);

        return $user;
    }

    /**
     * OLD-16: a failed sign-in is a security event, and none was recorded.
     *
     * On a real account the row is that account's — it shows in their own
     * history, which is where "someone tried my password" belongs. For a
     * name that matches nobody, the typed name is NOT stored: people type
     * their password into the username field, and the log would keep it.
     * The machine account is reported as unknown too — it has no password
     * anyone could have meant.
     */
    private function recordFailure(?User $user): void
    {
        $ip = (string) request()->ip();

        if ($user !== null && ! $user->isSystem()) {
            $this->activityLogger->log('user.login_failed', $user, ['ip' => $ip, 'username' => $user->username], actor: $user);

            return;
        }

        ActivityLog::create([
            'user_id' => null,
            'type' => 'user',
            'action' => 'login_failed_unknown',
            'properties' => ['ip' => $ip],
        ]);
    }
}
