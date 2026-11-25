<?php

namespace App\Services\Server\Backups\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Obtains a Google refresh token without ever needing a redirect URL.
 *
 * The panel shows a short code and a link; the operator approves on any device and the panel
 * polls until Google hands over a refresh token (the smart-TV flow: the machine has no
 * browser to redirect back to).
 *
 * Not the ordinary redirect flow: its redirect URI must be HTTPS on a verified domain you
 * own (raw IPs are refused), and a default install is reached at a `nip.io` host on a
 * self-signed certificate.
 *
 * Google limits this flow to a small scope list; the Drive entries are `drive.appdata` and
 * `drive.file`. `drive.file` only reaches files the app created and is non-sensitive, so it
 * avoids the restricted-scope assessment full `drive` triggers.
 */
class GoogleDeviceFlow
{
    /** Where the code is requested. Documented, stable, not configurable. */
    private const DEVICE_ENDPOINT = 'https://oauth2.googleapis.com/device/code';

    private const TOKEN_ENDPOINT = GoogleOauthTokens::TOKEN_ENDPOINT;

    /** The only Drive scope this flow allows that can write a backup. */
    private const SCOPE = GoogleOauthTokens::SCOPE;

    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

    /**
     * Ask Google for a user code.
     *
     * @return array{ok: bool, reason: string|null, device_code: string|null, user_code: string|null, verification_url: string|null, interval: int, expires_in: int}
     *                                                                                                                                                               `reason` is an i18n key, never Google's own (untranslated) text.
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
            // A wrong client id fails here, while the operator is still on the form.
            return $this->startFailure($this->classifyStart($response->json()));
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
            // `verification_url` is the documented field; newer responses carry `verification_uri`.
            'verification_url' => (string) ($response->json('verification_url')
                ?? $response->json('verification_uri')
                ?? 'https://www.google.com/device'),
            // Google's pacing: polling faster earns `slow_down`.
            'interval' => max(1, (int) ($response->json('interval') ?? 5)),
            'expires_in' => max(60, (int) ($response->json('expires_in') ?? 1800)),
        ];
    }

    /**
     * Ask whether the operator has approved yet.
     *
     * One poll, not a loop: a loop would hold an HTTP worker for up to half an hour.
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
            // Not an authorization failure: the caller keeps polling, the code is still valid.
            return $this->pollResult('pending');
        } catch (Throwable) {
            return $this->pollResult('failed', reason: 'storage.oauth.poll_failed');
        }

        if ($response->successful()) {
            $refreshToken = (string) ($response->json('refresh_token') ?? '');

            // Unusable without a refresh token: the access token expires within the hour.
            // Google omits it when the account already granted this client before.
            if ($refreshToken === '') {
                return $this->pollResult('failed', reason: 'storage.oauth.no_refresh_token');
            }

            return $this->pollResult('approved', refreshToken: $refreshToken);
        }

        return match ((string) $response->json('error')) {
            // Not approved yet.
            'authorization_pending' => $this->pollResult('pending'),
            // Polled faster than Google's interval: back off, do not fail.
            'slow_down' => $this->pollResult('slow_down'),
            'access_denied' => $this->pollResult('denied', reason: 'storage.oauth.denied'),
            'expired_token' => $this->pollResult('expired', reason: 'storage.oauth.code_expired'),
            default => $this->pollResult('failed', reason: 'storage.oauth.poll_failed'),
        };
    }

    /**
     * Why Google refused to issue a code.
     *
     * A wrong client id and a client id of the wrong *type* both arrive as `invalid_client`
     * but need different actions, so the `error_description` is read too. Google says
     * "Only clients of type 'TVs and Limited Input devices' can use the OAuth 2.0 flow ...".
     *
     * Matched on "limited input", not the full sentence: the quotes come back HTML-escaped.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function classifyStart(?array $body): string
    {
        $description = strtolower((string) ($body['error_description'] ?? ''));

        if (str_contains($description, 'limited input') || str_contains($description, 'limited-input')) {
            return 'storage.oauth.wrong_client_type';
        }

        return $this->isClientError((string) ($body['error'] ?? ''))
            ? 'storage.oauth.bad_client'
            : 'storage.oauth.start_failed';
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
