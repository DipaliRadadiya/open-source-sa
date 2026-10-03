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

        DB::transaction(function () use ($token) {
            // Wipe any previous token — a new enable replaces the old one.
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

        Log::info('central management enabled');

        return [
            'central_token' => $token,
            'masked' => $this->mask($token),
        ];
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
