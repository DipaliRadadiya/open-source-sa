<?php

namespace App\Services\Server;

use App\Exceptions\Server\CentralTokenException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manages the single central-management token on this OSS panel.
 *
 * The token is stored in the settings table (singleton — one row, keyed by
 * `id = 1`). It is never logged, never returned plain, and is revoked by
 * overwriting it with a new random value.
 */
class CentralTokenManager
{
    private const TOKEN_LENGTH = 32;

    /**
     * Generate and store a new central token, replacing any existing one.
     * Creates the settings row if it does not yet exist.
     */
    public function enable(): array
    {
        $token = $this->generate();

        $this->persist($token);

        Log::info('central management enabled');

        return [
            'central_token' => $token,
            'masked' => $this->mask($token),
        ];
    }

    /**
     * Store a token that was chosen elsewhere.
     *
     * The central panel mints the token before this server exists, hands it to
     * `install.sh`, and registers the same value at its end — so unlike
     * `enable()` the value is an input, not something to generate.
     *
     * Idempotent by construction: it writes the value it is given, so a re-run
     * of the installer with the same token converges rather than rotating the
     * credential out from under central. It does clear `central_token_used_at`,
     * which is right — "central has used this key" is not a claim to carry
     * across a reinstall.
     *
     * @throws \InvalidArgumentException when the token is blank. A silent no-op
     *                                   would leave central management off while
     *                                   the installer reported the token stored,
     *                                   which is exactly what the old installer
     *                                   did for every install it ran.
     */
    public function store(string $token): void
    {
        $token = trim($token);

        if ($token === '') {
            throw new \InvalidArgumentException('central token must not be empty');
        }

        $this->persist($token);

        // The token value is never logged, here or anywhere else.
        Log::info('central management token stored');
    }

    /**
     * Upsert the token into the settings singleton.
     *
     * Shared by `enable()` and `store()` rather than written twice. The row may
     * not exist yet — a fresh install stores a token before anything has ever
     * saved settings — so both paths need the insert branch, and a second copy
     * of it is a second place to get the `id = 1` singleton wrong.
     *
     * Note that `central_token_used_at` is cleared twice over: once by the
     * `disable()` call and again in the update branch. Neither is load-bearing
     * on its own — removing either leaves the behaviour intact, which a revert
     * check confirmed — so do not read one as the guard and delete the other
     * thinking it is dead.
     */
    private function persist(string $token): void
    {
        DB::transaction(function () use ($token) {
            // Wipe any previous token — a new one replaces the old.
            $this->disable();

            $setting = DB::table('settings')->where('id', 1)->first();

            if ($setting) {
                DB::table('settings')->where('id', 1)->update([
                    'central_token' => $token,
                    'central_token_used_at' => null,
                ]);
            } else {
                DB::table('settings')->insert([
                    'id' => 1,
                    'central_token' => $token,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Revoke the current token by overwriting it with null.
     */
    public function disable(): void
    {
        DB::table('settings')->where('id', 1)->update(['central_token' => null, 'central_token_used_at' => null]);

        Log::info('central management disabled');
    }

    /**
     * Return the current token status. Never returns the raw token.
     */
    public function status(): array
    {
        $row = DB::table('settings')->where('id', 1)->whereNotNull('central_token')->first();

        if (! $row) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'masked' => $this->mask($row->central_token),
            // Bug #49: a key is not a connection until Central has used it.
            'last_used_at' => $row->central_token_used_at,
        ];
    }

    /**
     * Validate a raw token against the stored value. Throws on mismatch.
     */
    public function validate(string $token): void
    {
        $stored = DB::table('settings')->where('id', 1)->value('central_token');

        if (! $stored || ! hash_equals($stored, $token)) {
            throw new CentralTokenException(
                message: __('errors/central.invalid_token'),
                feature: 'central',
            );
        }

        $this->recordUse();
    }

    /**
     * Note that Central used the key. At most once a minute: Central calls
     * on every request, and a write per request buys nothing the screen can
     * show.
     */
    private function recordUse(): void
    {
        DB::table('settings')->where('id', 1)
            ->where(fn ($query) => $query->whereNull('central_token_used_at')
                ->orWhere('central_token_used_at', '<', now()->subMinute()))
            ->update(['central_token_used_at' => now()]);
    }

    private function generate(): string
    {
        return 'sv_central_'.str_replace(['+', '/', '='], '', base64_encode(random_bytes(self::TOKEN_LENGTH)));
    }

    private function mask(string $token): string
    {
        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 12).str_repeat('*', max(0, strlen($token) - 12));
    }
}
