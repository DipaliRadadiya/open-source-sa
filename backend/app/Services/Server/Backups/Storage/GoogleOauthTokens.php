<?php

namespace App\Services\Server\Backups\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The half of Google OAuth that does not care how consent was obtained.
 *
 * A refresh token is a refresh token. Whether the operator approved a code on
 * their phone or came back through a browser redirect, what the panel stores is
 * identical and what it does with it afterwards is identical: trade it for an
 * access token, over and over, for as long as the grant lives.
 *
 * So this sits below both flows. {@see GoogleOauthRedirect} obtains the token;
 * {@see GoogleDriveOauthDriver}, {@see GoogleDriveWorkspace} and the
 * `google_oauth` disk all spend it through here. Keeping the spend path
 * separate from the acquire path is what lets the acquire path be replaced
 * without touching a single line that performs a backup.
 *
 * See `google-drive-oauth-design.md` §3b.
 */
class GoogleOauthTokens
{
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * The only Drive scope this panel asks for.
     *
     * `drive.file` grants access solely to files the app itself created, which
     * is exactly a backup destination's business and no more. It is also
     * non-sensitive, so an operator's own unverified OAuth app never faces the
     * restricted-scope security assessment that full `drive` would trigger.
     *
     * The cost, which the UI states rather than hides: a destination cannot see
     * archives uploaded by a *different* panel install.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /**
     * Trade the stored refresh token for an access token.
     *
     * Used by the driver on every operation and by preflight to prove the grant
     * is still alive before a backup is ever scheduled against it.
     *
     * @return array{ok: bool, access_token: string|null, reason: string|null}
     */
    public function accessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->post(self::TOKEN_ENDPOINT, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (ConnectionException) {
            return $this->failure('storage.test.unreachable');
        } catch (Throwable) {
            return $this->failure('storage.oauth.token_failed');
        }

        if ($response->successful() && (string) $response->json('access_token') !== '') {
            return ['ok' => true, 'access_token' => (string) $response->json('access_token'), 'reason' => null];
        }

        // `invalid_grant` is the one that matters operationally: the operator
        // revoked access, or left the OAuth app in "Testing", where Google
        // expires refresh tokens after about a week. Both need a human, and
        // both read identically from here — so the message names the likely
        // cause rather than the error code.
        return $this->failure(
            (string) $response->json('error') === 'invalid_grant'
                ? 'storage.oauth.revoked'
                : 'storage.test.invalid_credentials'
        );
    }

    /**
     * @return array{ok: bool, access_token: null, reason: string}
     */
    private function failure(string $reason): array
    {
        return ['ok' => false, 'access_token' => null, 'reason' => $reason];
    }

    private function timeout(): int
    {
        return (int) config('server.storage.oauth_timeout_seconds', 15);
    }
}
