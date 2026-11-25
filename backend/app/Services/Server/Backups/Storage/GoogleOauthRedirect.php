<?php

namespace App\Services\Server\Backups\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Obtains a Google refresh token through the ordinary browser redirect.
 *
 * Uses a Web application client, not the device flow ("TVs and Limited Input devices").
 *
 * Two hops: Google redirects a *browser* that carries no Sanctum token, so a bare API callback
 * could not tell who is connecting. The frontend page at `/integrations/storage/oauth/callback`
 * holds the user's token, reads `?code` and `?state` and forwards them as an authenticated
 * request. Only the page URL is registered with Google; the API path is internal and must never
 * be an authorized redirect URI.
 *
 * The single-use `code` is exchanged here, on the server: the client secret never reaches the
 * browser.
 */
class GoogleOauthRedirect
{
    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * The frontend page Google sends the browser back to. Operators paste it into Google Cloud
     * Console, so changing it breaks every panel that registered the old one.
     */
    public const CALLBACK_PATH = '/integrations/storage/oauth/callback';

    public function __construct(private GoogleOauthTokens $tokens) {}

    /**
     * Where to send the operator to approve.
     *
     * `access_type=offline` and `prompt=consent` are both required: without `offline` Google issues
     * no refresh token, and without `consent` it withholds it on every approval after the first.
     */
    public function authorizeUrl(string $clientId, string $state): string
    {
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => GoogleOauthTokens::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            // Keeps scopes already granted to this client.
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The redirect URI, used at authorize and at exchange time.
     *
     * Google refuses the pair if the two values differ by even a trailing slash, so both callers
     * read this method. It is also the exact text the operator pastes into Cloud Console.
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('server.storage.panel_url', ''), '/').self::CALLBACK_PATH;
    }

    /**
     * Whether the panel knows its own public origin.
     *
     * An unset FRONTEND_URL gives a relative redirect URI that Google rejects with a generic
     * `invalid_request`.
     */
    public function configured(): bool
    {
        $base = trim((string) config('server.storage.panel_url', ''));

        return $base !== '' && str_starts_with($base, 'http');
    }

    /**
     * Trade the one-time code for a refresh token.
     *
     * @return array{ok: bool, refresh_token: string|null, reason: string|null}
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code): array
    {
        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->post(GoogleOauthTokens::TOKEN_ENDPOINT, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirectUri(),
                ]);
        } catch (ConnectionException) {
            return $this->failure('storage.test.unreachable');
        } catch (Throwable) {
            return $this->failure('storage.oauth.token_failed');
        }

        if (! $response->successful()) {
            return $this->failure($this->classify($response->json()));
        }

        $refreshToken = (string) ($response->json('refresh_token') ?? '');

        // Without a refresh token the access token dies within the hour and nothing could renew
        // it. `prompt=consent` makes this nearly unreachable; keep the guard.
        if ($refreshToken === '') {
            return $this->failure('storage.oauth.no_refresh_token');
        }

        return ['ok' => true, 'refresh_token' => $refreshToken, 'reason' => null];
    }

    /**
     * Why Google refused the exchange.
     *
     * Separates a *setup* mistake (a line in Cloud Console) from an *attempt* mistake (press
     * Connect again).
     *
     * Reads `error_description` as well as `error`: Google reuses `invalid_request` and
     * `invalid_client` for several unrelated setup faults, so the code alone says too little.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function classify(?array $body): string
    {
        $error = (string) ($body['error'] ?? '');
        $description = strtolower((string) ($body['error_description'] ?? ''));

        // The most likely setup fault: the URI in Cloud Console differs from the one sent.
        if (str_contains($description, 'redirect_uri_mismatch')
            || str_contains($description, 'redirect uri mismatch')
            || $error === 'redirect_uri_mismatch') {
            return 'storage.oauth.redirect_mismatch';
        }

        // The client was created as a different application type.
        if (str_contains($description, 'limited input')
            || str_contains($description, 'limited-input')) {
            return 'storage.oauth.wrong_client_type';
        }

        // The code was spent or expired: press Connect again.
        if ($error === 'invalid_grant') {
            return 'storage.oauth.code_expired';
        }

        return in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_request'], true)
            ? 'storage.oauth.bad_client'
            : 'storage.oauth.token_failed';
    }

    /**
     * @return array{ok: bool, refresh_token: null, reason: string}
     */
    private function failure(string $reason): array
    {
        return ['ok' => false, 'refresh_token' => null, 'reason' => $reason];
    }

    private function timeout(): int
    {
        return (int) config('server.storage.oauth_timeout_seconds', 15);
    }
}
