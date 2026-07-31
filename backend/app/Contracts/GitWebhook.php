<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * How one git host proves a webhook delivery is really from it.
 *
 * A separate strategy from {@see GitProvider} on purpose: that one is bound to
 * a stored account and its token, and a webhook can arrive for an application
 * built from a **public** repository, which has no account at all. What decides
 * the scheme is the provider recorded on the application when the webhook was
 * enabled — never the request's own headers, which would let a caller choose
 * which verification runs.
 *
 * Implementations must treat every byte of the request as adversarial. Two
 * fields are read from the payload and nothing else: which branch was pushed,
 * and the delivery id used to reject replays.
 */
interface GitWebhook
{
    /**
     * Whether this delivery is authentic.
     *
     * Verified against the **raw** body — the signature covers the exact bytes
     * sent, so anything that re-encodes the JSON (even identically-meaning
     * JSON) produces a different digest and a false rejection.
     *
     * Must compare in constant time, and must fail closed: a missing header, a
     * malformed one, or an unparseable signature is a rejection, never a pass.
     */
    public function verify(Request $request, string $secret, string $body): bool;

    /**
     * Whether this is a push — the only event that deploys anything.
     *
     * Providers send many event types down one URL, and a hook configured too
     * broadly would otherwise deploy on a comment.
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
     * Null when the provider sends none, in which case the delivery cannot be
     * de-duplicated and the signature is the only protection.
     */
    public function deliveryId(Request $request): ?string;

    /**
     * Which direction the secret travels.
     *
     * - `generate` — the panel mints it and the user copies it into the repo.
     * - `either` — the user may also paste one, and the two are not equivalent.
     *   GitLab is this: its recommended **signing token** is minted by GitLab
     *   and shown once, so it can only be pasted in, while its legacy plaintext
     *   **secret token** is any string and can be generated here. The UI needs
     *   to offer both, or every GitLab user ends up on the weaker one.
     *
     * There is deliberately no paste-only value: none of the three providers is,
     * and an enum case nothing returns is a branch that drifts out of step with
     * the code that claims to handle it.
     */
    public function secretSource(): string;

    /**
     * Which check a given stored secret will actually get: `signature` for an
     * HMAC over the body, `token` for a plaintext shared value.
     *
     * Surfaced so the panel can *say* which one is in force. GitLab is the
     * reason: with no secret supplied the panel can only generate a legacy
     * plaintext token, and quietly leaving someone on the scheme GitLab labels
     * not recommended — without telling them a stronger one exists — is a worse
     * failure than refusing.
     */
    public function verificationMode(string $secret): string;
}
