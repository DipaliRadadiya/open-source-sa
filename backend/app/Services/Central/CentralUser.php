<?php

namespace App\Services\Central;

use App\Models\User;
use App\Services\AdministratorRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The machine account the central panel's token belongs to.
 *
 * Why a separate account rather than hanging the token off the admin who
 * pressed the button: that admin can be deleted or demoted, which would kill
 * the integration for reasons nobody would connect to it — and every action
 * the central panel took would appear in the activity log under a person's
 * name. A distinct identity makes the log attribute those rows to the
 * integration, which is the part that matters when someone asks what the
 * vendor did on their server.
 */
class CentralUser
{
    public const USERNAME = 'central';

    /**
     * Find or create the account. Idempotent — connecting, disconnecting and
     * reconnecting reuses the same identity, so the activity log stays
     * attributable across the whole history.
     */
    public function ensure(): User
    {
        $existing = User::query()->where('is_system', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $user = User::create([
            'name' => $this->name(),
            // A human may already hold the obvious username. Taking it over
            // would mint a full-access token on a real person's account, so
            // the machine account steps aside instead.
            'username' => $this->availableUsername(),
            'is_admin' => true,
            'is_system' => true,
            // Never used: the login path rejects system accounts outright.
            // Random rather than empty so no hash can ever be guessed at.
            'password' => Hash::make(Str::random(64)),
        ]);

        $user->roles()->attach(app(AdministratorRole::class)->ensure());

        return $user;
    }

    private function name(): string
    {
        return (string) config('branding.central_name', config('branding.name'));
    }

    private function availableUsername(): string
    {
        if (! User::query()->where('username', self::USERNAME)->exists()) {
            return self::USERNAME;
        }

        return self::USERNAME.'-'.Str::lower(Str::random(8));
    }
}
