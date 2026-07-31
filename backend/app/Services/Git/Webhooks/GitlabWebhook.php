<?php

namespace App\Services\Git\Webhooks;

use App\Contracts\GitWebhook;
use Illuminate\Http\Request;

/**
 * GitLab, which has two schemes, and the panel supports both because they are
 * not interchangeable:
 *
 *  - **Signing token (what GitLab now recommends).** Delivery follows the
 *    Standard Webhooks spec: `webhook-id`, `webhook-timestamp`, and
 *    `webhook-signature` holding a space-separated list of `v1,{base64}`
 *    entries. The signature covers **`{id}.{timestamp}.{raw body}`**, keyed by
 *    the token with its `whsec_` prefix stripped and the remainder
 *    base64-decoded. GitLab currently sends one entry and says that may change,
 *    so the list is walked.
 *  - **Secret token (legacy, GitLab labels it not recommended).** The secret
 *    itself arrives in plain text in `X-Gitlab-Token`.
 *
 * **The panel cannot generate a signing token** — GitLab mints it and shows it
 * once — so that value travels from GitLab into the panel, the opposite
 * direction from GitHub and Bitbucket. Which scheme is in force is decided by
 * the stored secret's own `whsec_` prefix, GitLab's marker, and never by which
 * headers the request happens to carry: a caller who could pick would pick the
 * plaintext one.
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
        // Both: the signing token comes from GitLab, the legacy one can come
        // from here. See the class docblock.
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

        // Replay protection, which the signature alone does not give: a captured
        // delivery stays validly signed forever. GitLab's own docs call this
        // out. Rejected before any HMAC work, so a flood of stale replays costs
        // nothing.
        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $key = base64_decode(substr($secret, strlen(self::SIGNING_PREFIX)), true);

        // A token that is not valid base64 cannot verify anything, and treating
        // the decode failure as an empty key would compute a real HMAC with a
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

        // Compared in constant time even though it is a plain equality: the
        // header is attacker-controlled and a naive `===` on a secret leaks its
        // prefix through timing.
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
