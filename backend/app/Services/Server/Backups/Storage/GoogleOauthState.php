<?php

namespace App\Services\Server\Backups\Storage;

use App\Models\StorageDestination;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ties a Google callback back to the request that started it.
 *
 * The panel's API is token-authenticated with no session, so `state` cannot be compared against
 * a session value; the binding travels in the parameter. A bare destination id there would let
 * anyone post a callback naming any destination and overwrite its credentials with their own grant.
 *
 * Two properties:
 *
 * - **Unforgeable.** The payload is sealed with Laravel's authenticated encrypter, so a tampered
 *   or hand-written `state` fails to decrypt.
 * - **Single-use.** The sealed payload carries a nonce that must match one held server-side, and
 *   consuming it burns the nonce, so a replayed `state` (browser history, referrer) is refused
 *   even though it decrypts.
 *
 * The expiry timestamp is belt-and-braces for caches that do not honour TTLs strictly.
 *
 * See `google-drive-oauth-design.md` §3b.
 */
class GoogleOauthState
{
    /**
     * Seal a destination id into an opaque `state` and remember the nonce.
     */
    public function issue(StorageDestination $destination): string
    {
        $nonce = Str::random(40);
        $ttl = $this->ttl();

        Cache::put($this->cacheKey($destination->id), $nonce, now()->addSeconds($ttl));

        return Crypt::encryptString((string) json_encode([
            'did' => $destination->id,
            'nonce' => $nonce,
            'exp' => now()->addSeconds($ttl)->getTimestamp(),
        ]));
    }

    /**
     * Read a destination id back out, once.
     *
     * Returns a reason rather than throwing: each failure is shown as a sentence on the callback
     * page, and "already used" differs from "not issued by this panel".
     *
     * @return array{ok: bool, destination_id: int|null, reason: string|null}
     */
    public function consume(string $state): array
    {
        if ($state === '') {
            return $this->failure('storage.oauth.state_invalid');
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (Throwable) {
            // Tampered, truncated, or sealed by another panel (the app key differs per install).
            return $this->failure('storage.oauth.state_invalid');
        }

        if (! is_array($payload)) {
            return $this->failure('storage.oauth.state_invalid');
        }

        $destinationId = (int) ($payload['did'] ?? 0);
        $nonce = (string) ($payload['nonce'] ?? '');
        $expires = (int) ($payload['exp'] ?? 0);

        if ($destinationId <= 0 || $nonce === '') {
            return $this->failure('storage.oauth.state_invalid');
        }

        if ($expires <= now()->getTimestamp()) {
            return $this->failure('storage.oauth.state_expired');
        }

        $expected = Cache::get($this->cacheKey($destinationId));

        // Absent means already consumed or the window closed: "start again", not a setup error.
        if (! is_string($expected) || $expected === '') {
            return $this->failure('storage.oauth.state_expired');
        }

        // Constant-time: a timing oracle on the nonce would defeat single-use.
        if (! hash_equals($expected, $nonce)) {
            return $this->failure('storage.oauth.state_invalid');
        }

        // Burned before the caller uses it, so a request failing downstream cannot be replayed.
        Cache::forget($this->cacheKey($destinationId));

        return ['ok' => true, 'destination_id' => $destinationId, 'reason' => null];
    }

    /**
     * Drop an in-flight attempt when a destination is reconnected or removed, so an abandoned
     * `state` cannot be redeemed.
     */
    public function forget(StorageDestination $destination): void
    {
        Cache::forget($this->cacheKey($destination->id));
    }

    private function cacheKey(int $destinationId): string
    {
        return "storage:oauth:state:{$destinationId}";
    }

    private function ttl(): int
    {
        return max(60, (int) config('server.storage.oauth_state_ttl_seconds', 900));
    }

    /**
     * @return array{ok: bool, destination_id: null, reason: string}
     */
    private function failure(string $reason): array
    {
        return ['ok' => false, 'destination_id' => null, 'reason' => $reason];
    }
}
