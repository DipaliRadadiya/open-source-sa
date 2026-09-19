<?php

namespace App\Services\Server\Backups\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Obtains a Google refresh token without ever needing a redirect URL.
 *
 * The panel shows a short code and a link; the operator opens the link on any
 * device, approves, and the panel polls until Google hands over a refresh
 * token. The same flow a smart TV uses, and for the same reason: the machine
 * asking for access has no browser and cannot be redirected back to.
 *
 * **Why this and not the ordinary redirect flow.** A redirect URI must be
 * HTTPS on a domain you own — raw IPs are refused, and the consent screen's
 * authorized domain has to be verified in Search Console. Our default install
 * is reached at `panel.1-2-3-4.nip.io` (`install.sh:449`; `--domain=` is the
 * opt-in), on a self-signed certificate when no real domain was given. So the
 * redirect flow cannot serve a *default* panel at all, and `nip.io` is not a
 * domain anybody here owns. Swapping "free Gmail users are excluded" for
 * "default installs are excluded" would have been no trade at all.
 *
 * Google restricts this flow to a small scope list, of which the Drive entries
 * are `drive.appdata` and `drive.file`. That is a gift rather than a
 * limitation: `drive.file` grants access only to files the app itself created,
 * which is exactly a backup destination's business, and it is non-sensitive,
 * so it avoids the restricted-scope security assessment full `drive` triggers.
 *
 * See `google-drive-oauth-design.md`.
 */
class GoogleDeviceFlow
{
    /** Where the code is requested. Documented, stable, not configurable. */
    private const DEVICE_ENDPOINT = 'https://oauth2.googleapis.com/device/code';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /** The only Drive scope this flow allows that can write a backup. */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

    /**
     * Ask Google for a user code.
     *
     * @return array{ok: bool, reason: string|null, device_code: string|null, user_code: string|null, verification_url: string|null, interval: int, expires_in: int}
     *                                                                                                                                                               `reason` is an i18n key, never Google's own text — the same rule the
     *                                                                                                                                                               rest of this subsystem follows, because those strings carry URLs and
     *                                                                                                                                                               occasionally identifiers, and they are not translated.
     */
    public function start(string $clientId): array
    {
        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->post(self::DEVICE_ENDPOINT, [
                    'client_id' => $clientId,
                    'scope' => self::SCOPE,
                ]);
        } catch (ConnectionException) {
            return $this->startFailure('storage.test.unreachable');
        } catch (Throwable) {
            return $this->startFailure('storage.oauth.start_failed');
        }

        if (! $response->successful()) {
            // A wrong client id fails here rather than at approval time, which
            // is the good case: the operator is still looking at the form they
            // pasted it into.
            return $this->startFailure(
                $this->isClientError((string) $response->json('error'))
                    ? 'storage.oauth.bad_client'
                    : 'storage.oauth.start_failed'
            );
        }

        $deviceCode = (string) $response->json('device_code');
        $userCode = (string) $response->json('user_code');

        if ($deviceCode === '' || $userCode === '') {
            return $this->startFailure('storage.oauth.start_failed');
        }

        return [
            'ok' => true,
            'reason' => null,
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            // `verification_url` is the documented field; newer responses also
            // carry `verification_uri`. Reading both means a field rename by
            // Google does not leave the operator with a code and nowhere to
            // type it.
            'verification_url' => (string) ($response->json('verification_url')
                ?? $response->json('verification_uri')
                ?? 'https://www.google.com/device'),
            // Google's own pacing. Polling faster earns `slow_down`, so the
            // caller is told the interval rather than guessing one.
            'interval' => max(1, (int) ($response->json('interval') ?? 5)),
            'expires_in' => max(60, (int) ($response->json('expires_in') ?? 1800)),
        ];
    }

    /**
     * Ask whether the operator has approved yet.
     *
     * Deliberately one poll, not a loop. A loop here would hold an HTTP worker
     * for up to half an hour waiting on a human; the caller polls instead and
     * the browser shows progress while it does.
     *
     * @return array{status: 'approved'|'pending'|'slow_down'|'denied'|'expired'|'failed', refresh_token: string|null, reason: string|null}
     */
    public function poll(string $clientId, string $clientSecret, string $deviceCode): array
    {
        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->post(self::TOKEN_ENDPOINT, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'device_code' => $deviceCode,
                    'grant_type' => self::GRANT_TYPE,
                ]);
        } catch (ConnectionException) {
            // Not a failure of the authorization — the network blinked. The
            // caller keeps polling, because the code is still valid and the
            // operator may already have approved it.
            return $this->pollResult('pending');
        } catch (Throwable) {
            return $this->pollResult('failed', reason: 'storage.oauth.poll_failed');
        }

        if ($response->successful()) {
            $refreshToken = (string) ($response->json('refresh_token') ?? '');

            // Approval without a refresh token is unusable: the access token
            // expires within the hour and nothing could renew it, so a 3am
            // backup would fail forever. Google omits it when the account has
            // already granted this client before, which is why the caller must
            // surface a real instruction rather than storing half a credential.
            if ($refreshToken === '') {
                return $this->pollResult('failed', reason: 'storage.oauth.no_refresh_token');
            }

            return $this->pollResult('approved', refreshToken: $refreshToken);
        }

        return match ((string) $response->json('error')) {
            // Nobody has approved it yet. The overwhelmingly common answer.
            'authorization_pending' => $this->pollResult('pending'),
            // We polled faster than Google's interval. Back off, do not fail.
            'slow_down' => $this->pollResult('slow_down'),
            'access_denied' => $this->pollResult('denied', reason: 'storage.oauth.denied'),
            'expired_token' => $this->pollResult('expired', reason: 'storage.oauth.code_expired'),
            default => $this->pollResult('failed', reason: 'storage.oauth.poll_failed'),
        };
    }

    /**
     * Trade the stored refresh token for an access token.
     *
     * Used by the driver on every operation and by preflight to prove the
     * grant is still alive before a backup is scheduled against it.
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
            return ['ok' => false, 'access_token' => null, 'reason' => 'storage.test.unreachable'];
        } catch (Throwable) {
            return ['ok' => false, 'access_token' => null, 'reason' => 'storage.oauth.poll_failed'];
        }

        if ($response->successful() && (string) $response->json('access_token') !== '') {
            return ['ok' => true, 'access_token' => (string) $response->json('access_token'), 'reason' => null];
        }

        // `invalid_grant` is the one that matters operationally: the operator
        // revoked access, or left the OAuth app in "Testing", where Google
        // expires refresh tokens after about a week. Both need a human, and
        // both read identically from here — so the message names the likely
        // cause rather than the error code.
        return [
            'ok' => false,
            'access_token' => null,
            'reason' => (string) $response->json('error') === 'invalid_grant'
                ? 'storage.oauth.revoked'
                : 'storage.test.invalid_credentials',
        ];
    }

    private function isClientError(string $error): bool
    {
        return in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_request'], true);
    }

    /**
     * @return array{ok: bool, reason: string|null, device_code: null, user_code: null, verification_url: null, interval: int, expires_in: int}
     */
    private function startFailure(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'device_code' => null,
            'user_code' => null,
            'verification_url' => null,
            'interval' => 5,
            'expires_in' => 0,
        ];
    }

    /**
     * @return array{status: string, refresh_token: string|null, reason: string|null}
     */
    private function pollResult(string $status, ?string $refreshToken = null, ?string $reason = null): array
    {
        return ['status' => $status, 'refresh_token' => $refreshToken, 'reason' => $reason];
    }

    private function timeout(): int
    {
        return (int) config('server.storage.oauth_timeout_seconds', 15);
    }
}
