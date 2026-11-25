<?php

namespace App\Services\Server\Backups\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The half of Google OAuth that does not care how consent was obtained: trade the stored refresh
 * token for an access token.
 *
 * {@see GoogleOauthRedirect} obtains the token; {@see GoogleDriveOauthDriver},
 * {@see GoogleDriveWorkspace} and the `google_oauth` disk spend it through here. The acquire path
 * can be replaced without touching any backup code.
 */
class GoogleOauthTokens
{
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * The only Drive scope this panel asks for.
     *
     * `drive.file` reaches only files the app itself created, which is all a backup destination
     * needs. It is non-sensitive, so an unverified OAuth app avoids the restricted-scope review.
     * Cost: a destination cannot see archives uploaded by a *different* panel install.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /**
     * Trade the stored refresh token for an access token.
     *
     * Used on every driver operation and by preflight, to prove the grant is still alive.
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

        // `invalid_grant`: access was revoked, or the OAuth app is in "Testing", where Google
        // expires refresh tokens after about a week. Both need a human.
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
