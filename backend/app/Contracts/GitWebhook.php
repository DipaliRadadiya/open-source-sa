<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * How one git host proves a webhook delivery is really from it.
 *
 * Separate from {@see GitProvider} because a webhook can arrive for an application built from a
 * public repository, which has no stored account. The scheme comes from the provider recorded on
 * the application, never from the request's headers (a caller could pick the verification).
 *
 * Treat every byte of the request as adversarial: only the pushed branch and the delivery id
 * (replay check) are read from the payload.
 */
interface GitWebhook
{
    /**
     * Whether this delivery is authentic.
     *
     * Verify against the raw body: re-encoding the JSON changes the digest and causes a false
     * rejection. Compare in constant time and fail closed (missing or malformed header = reject).
     */
    public function verify(Request $request, string $secret, string $body): bool;

    /**
     * Whether this is a push, the only event that deploys (many event types share one URL).
     */
    public function isPush(Request $request): bool;

    /**
     * The branch this push touched, or null when the payload does not say.
     *
     * Null is not "any branch": the caller refuses to deploy without a match.
     */
    public function pushedBranch(array $payload): ?string;

    /**
     * The provider's own id for this delivery, used to reject a replay.
     *
     * Null when the provider sends none: the signature is then the only protection.
     */
    public function deliveryId(Request $request): ?string;

    /**
     * Which direction the secret travels.
     *
     * - `generate`: the panel mints it and the user copies it into the repo.
     * - `either`: the user may also paste one. GitLab: its recommended signing token is minted
     *   by GitLab (paste only), its legacy secret token can be generated here. Offer both.
     *
     * There is deliberately no paste-only value: no provider returns it.
     */
    public function secretSource(): string;

    /**
     * Which check a stored secret will get: `signature` (HMAC over the body) or `token` (plaintext
     * shared value).
     *
     * Surfaced so the panel can say which is in force, instead of silently leaving a GitLab user
     * on the legacy plaintext token.
     */
    public function verificationMode(string $secret): string;
}
