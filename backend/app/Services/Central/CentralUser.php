<?php

namespace App\Services\Central;

use App\Models\User;
use App\Services\AdministratorRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The machine account the central panel's token belongs to.
 *
 * Separate from the admin who connected it: that admin can be deleted or demoted, which would
 * silently kill the integration, and the central panel's actions would be logged under a
 * person's name instead of the integration's.
 */
class CentralUser
{
    public const USERNAME = 'central';

    /**
     * Find or create the account. Idempotent: reconnecting reuses the same identity, so the
     * activity log stays attributable.
     */
    public function ensure(): User
    {
        $existing = User::query()->where('is_system', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $user = User::create([
            'name' => $this->name(),
            // A human may already hold the username; taking it over would mint a full-access token
            // on a real person's account, so the machine account steps aside.
            'username' => $this->availableUsername(),
            'is_admin' => true,
            'is_system' => true,
            // Never used (login rejects system accounts); random so no hash can be guessed.
            'password' => Hash::make(Str::random(64)),
        ]);

        $user->roles()->attach(app(AdministratorRole::class)->ensure());

        return $user;
    }

    /**
     * What the activity log calls this account.
     *
     * The branding name and nothing else: what distinguishes this account from a person is the
     * `central` username and its `is_system` flag, not a suffix on its display name.
     */
    private function name(): string
    {
        return trim((string) config('branding.name'));
    }

    private function availableUsername(): string
    {
        if (! User::query()->where('username', self::USERNAME)->exists()) {
            return self::USERNAME;
        }

        return self::USERNAME.'-'.Str::lower(Str::random(8));
    }
}
