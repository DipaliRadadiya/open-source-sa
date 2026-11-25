<?php

namespace App\Services\Git\Webhooks;

use App\Contracts\GitWebhook;
use Illuminate\Http\Request;

/**
 * GitLab has two webhook schemes and the panel supports both:
 *
 *  - **Signing token (recommended).** Standard Webhooks spec: `webhook-id`, `webhook-timestamp`
 *    and `webhook-signature`, a space-separated list of `v1,{base64}` entries (walked, as GitLab
 *    may send more than one). The signature covers **`{id}.{timestamp}.{raw body}`**, keyed by the
 *    token with its `whsec_` prefix stripped and the remainder base64-decoded.
 *  - **Secret token (legacy).** The secret arrives in plain text in `X-Gitlab-Token`.
 *
 * **The panel cannot generate a signing token**: GitLab mints it and shows it once, so the value
 * travels from GitLab into the panel (the opposite of GitHub and Bitbucket). The scheme is chosen
 * by the stored secret's `whsec_` prefix, never by the request's headers: a caller who could pick
 * would pick the plaintext one.
 */
class GitlabWebhook implements GitWebhook
{
    private const SIGNING_PREFIX = 'whsec_';

    public function verify(Request $request, string $secret, string $body): bool
    {
        return $this->isSigningToken($secret)
            ? $this->verifySignature($request, $secret, $body)
            : $this->verifyPlainToken($request, $secret);
    }

    public function isPush(Request $request): bool
    {
        return $request->header('X-Gitlab-Event') === 'Push Hook';
    }

    public function pushedBranch(array $payload): ?string
    {
        return Ref::branch($payload['ref'] ?? null);
    }

    public function deliveryId(Request $request): ?string
    {
        // `webhook-id` on a signed delivery, the older UUID otherwise.
        return $request->header('webhook-id') ?? $request->header('X-Gitlab-Event-UUID');
    }

    public function secretSource(): string
    {
        // Both: the signing token comes from GitLab, the legacy one from here.
        return 'either';
    }

    public function verificationMode(string $secret): string
    {
        return $this->isSigningToken($secret) ? 'signature' : 'token';
    }

    /**
     * Whether a stored secret is a signing token rather than a plain one.
     */
    public function isSigningToken(string $secret): bool
    {
        return str_starts_with($secret, self::SIGNING_PREFIX);
    }

    private function verifySignature(Request $request, string $secret, string $body): bool
    {
        $id = $request->header('webhook-id');
        $timestamp = $request->header('webhook-timestamp');
        $signatures = $request->header('webhook-signature');

        if ($id === null || $timestamp === null || $signatures === null) {
            return false;
        }

        // Replay protection: a captured delivery stays validly signed forever. Rejected before
        // any HMAC work, so stale replays cost nothing.
        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $key = base64_decode(substr($secret, strlen(self::SIGNING_PREFIX)), true);

        // Invalid base64 must not become an empty key: that would compute a real HMAC with a
        // guessable one.
        if ($key === false || $key === '') {
            return false;
        }

        $expected = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        foreach (explode(' ', $signatures) as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function verifyPlainToken(Request $request, string $secret): bool
    {
        $sent = $request->header('X-Gitlab-Token');

        // Constant time: the header is attacker-controlled and `===` leaks the secret's prefix.
        return $sent !== null && hash_equals($secret, $sent);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('server.webhooks.timestamp_tolerance', 300);

        return abs(now()->getTimestamp() - (int) $timestamp) <= $tolerance;
    }
}
