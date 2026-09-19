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
 * Normally `state` is compared against a value held in the session. The panel's
 * API is Sanctum-token authenticated and has no session, so the binding has to
 * be carried in the parameter itself — and a destination id sitting in a query
 * string is an open invitation: anyone could post a callback naming any
 * destination and overwrite its credentials with a grant on an account they
 * control.
 *
 * Two properties, because one is not enough:
 *
 * - **Unforgeable.** The payload is sealed with Laravel's encrypter, which is
 *   authenticated — a tampered or hand-written `state` fails to decrypt rather
 *   than decoding into something attacker-chosen.
 * - **Single-use.** Sealing alone still allows *replay*: a `state` captured
 *   from a browser's history or a referrer header would stay valid forever. So
 *   the sealed payload carries a nonce that must match one held server-side,
 *   and consuming it burns the nonce. A second callback with the same `state`
 *   is refused even though it decrypts perfectly.
 *
 * The expiry is belt-and-braces. The cache entry already lapses on its own; the
 * timestamp inside means a `state` cannot outlive its window even if the cache
 * is one that does not honour TTLs strictly.
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
     * Deliberately returns a reason rather than throwing: every failure here is
     * something the operator sees as a sentence on a callback page, and "this
     * link has already been used" is a different instruction from "this link
     * was not issued by this panel".
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
            // Tampered, truncated, or sealed by a different panel — the app key
            // differs per install, so a `state` from elsewhere lands here too.
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

        // Absent means already consumed, or the window closed. Both are
        // "start again", not "something is wrong with your setup".
        if (! is_string($expected) || $expected === '') {
            return $this->failure('storage.oauth.state_expired');
        }

        // Constant-time: the nonce is a secret, and a timing oracle on it would
        // hand back the one thing that makes the seal single-use.
        if (! hash_equals($expected, $nonce)) {
            return $this->failure('storage.oauth.state_invalid');
        }

        // Burned before the caller does anything with it, so a request that
        // fails downstream still cannot be replayed.
        Cache::forget($this->cacheKey($destinationId));

        return ['ok' => true, 'destination_id' => $destinationId, 'reason' => null];
    }

    /**
     * Drop an in-flight attempt — used when a destination is reconnected or
     * removed, so an abandoned `state` cannot be redeemed afterwards.
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
