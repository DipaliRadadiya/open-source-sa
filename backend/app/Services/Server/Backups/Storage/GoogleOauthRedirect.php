<?php

namespace App\Services\Server\Backups\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Obtains a Google refresh token through the ordinary browser redirect.
 *
 * Replaces the device flow, and the reason is not technical: the device flow
 * required the operator to pick a Google client type called "TVs and Limited
 * Input devices" in order to back up a web server. That is absurd on its face,
 * it was the first thing that went wrong in real use, and no amount of good
 * error copy makes it un-weird. A Web application client is what a person
 * expects to create for a panel they reach in a browser.
 *
 * **Two hops, and the second one is the point.** Google redirects a *browser*,
 * which arrives at the panel carrying no Sanctum token — so a bare API callback
 * could not tell who was connecting. The frontend page at
 * `/integrations/storage/oauth/callback` is the only participant that still
 * holds the user's token, so it reads `?code` and `?state` and forwards them as
 * an authenticated request. Only the page URL is ever registered with Google;
 * the API path is internal and must never be an authorized redirect URI.
 *
 * The `code` is single-use and short-lived, and is therefore exchanged here, on
 * the server. The client secret never reaches the browser.
 *
 * See `google-drive-oauth-design.md` §3b.
 */
class GoogleOauthRedirect
{
    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * The path Google sends the browser back to. A frontend page, not an API
     * route, and a constant because it is a string an operator pastes into
     * Google Cloud Console — it cannot drift without breaking every panel that
     * registered the old one.
     */
    public const CALLBACK_PATH = '/integrations/storage/oauth/callback';

    public function __construct(private GoogleOauthTokens $tokens) {}

    /**
     * Where to send the operator to approve.
     *
     * `access_type=offline` and `prompt=consent` are both load-bearing and for
     * the same reason. Without `offline` Google issues no refresh token at all;
     * without `consent` it withholds the refresh token on every approval after
     * the first, because the account has already granted this client. A
     * re-connect would then appear to succeed and store half a credential whose
     * access token dies within the hour.
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
            // Keeps any scope the account has already granted this client, so
            // approving here cannot quietly revoke a grant made elsewhere.
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The redirect URI, derived once and used twice.
     *
     * Google compares the value sent at authorize time with the value sent at
     * exchange time and refuses the pair if they differ by so much as a
     * trailing slash — so both callers read this method rather than building
     * the string themselves. It is also the exact text the operator pastes into
     * Cloud Console, which is why the panel shows it rather than describing it.
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('server.storage.panel_url', ''), '/').self::CALLBACK_PATH;
    }

    /**
     * Whether the panel knows its own public origin.
     *
     * An unset FRONTEND_URL would otherwise produce a redirect URI of
     * `/integrations/...` — a relative path that Google rejects with a generic
     * `invalid_request`, sending the operator to check the client id they
     * pasted perfectly correctly.
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

        // Approval without a refresh token is unusable: the access token
        // expires within the hour and nothing could renew it, so a 3am backup
        // would fail forever. `prompt=consent` above makes this very nearly
        // unreachable — but storing half a credential is bad enough to keep a
        // guard for the case it is not.
        if ($refreshToken === '') {
            return $this->failure('storage.oauth.no_refresh_token');
        }

        return ['ok' => true, 'refresh_token' => $refreshToken, 'reason' => null];
    }

    /**
     * Why Google refused the exchange.
     *
     * The distinction that matters is between a mistake in the *setup* and a
     * mistake in the *attempt*, because they send the operator to completely
     * different places. A mismatched redirect URI is a line in Cloud Console; a
     * stale code just means pressing Connect again.
     *
     * Read from `error_description` as well as `error`, because Google reuses
     * `invalid_grant` for an expired code and a reused one, and reuses
     * `invalid_request`/`invalid_client` for several unrelated setup faults.
     * The device flow taught this the expensive way: a correctly-copied client
     * id of the wrong *type* came back as `invalid_client` and was reported as
     * "check it was copied whole", sending someone to re-verify a perfect
     * value. The code alone is not enough to say anything useful.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function classify(?array $body): string
    {
        $error = (string) ($body['error'] ?? '');
        $description = strtolower((string) ($body['error_description'] ?? ''));

        // The single most likely setup fault, and the one worth naming exactly:
        // the URI in Cloud Console does not match what the panel just sent.
        // Google says so plainly and the panel can show both strings.
        if (str_contains($description, 'redirect_uri_mismatch')
            || str_contains($description, 'redirect uri mismatch')
            || $error === 'redirect_uri_mismatch') {
            return 'storage.oauth.redirect_mismatch';
        }

        // Someone created the client as a different application type. Same
        // class of mistake the device flow hit, arriving from the other side.
        if (str_contains($description, 'limited input')
            || str_contains($description, 'limited-input')) {
            return 'storage.oauth.wrong_client_type';
        }

        // The code was already spent, or it aged out. Both mean "press Connect
        // again", and neither reflects on the credentials.
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
