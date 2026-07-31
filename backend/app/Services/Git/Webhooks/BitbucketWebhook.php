<?php

namespace App\Services\Git\Webhooks;

use App\Contracts\GitWebhook;
use Illuminate\Http\Request;

/**
 * Bitbucket Cloud: HMAC-SHA256 of the raw body, hex, in `X-Hub-Signature`.
 *
 * Webhook secrets are relatively recent here (Atlassian shipped them in late
 * 2024); older material says Bitbucket cannot authenticate webhooks at all,
 * which is no longer true. Atlassian's docs note the hash algorithm may change,
 * so the `sha256=` prefix is checked rather than assumed — an unknown algorithm
 * is a rejection, not a guess.
 *
 * Bitbucket's push payload is shaped differently from the other two: instead of
 * one `ref` it sends `push.changes[]`, each with a `new` that may be a branch,
 * a tag, or null for a deletion.
 */
class BitbucketWebhook implements GitWebhook
{
    public function verify(Request $request, string $secret, string $body): bool
    {
        return HmacSignature::matches(
            $request->header('X-Hub-Signature'),
            $secret,
            $body,
        );
    }

    public function isPush(Request $request): bool
    {
        return $request->header('X-Event-Key') === 'repo:push';
    }

    public function pushedBranch(array $payload): ?string
    {
        foreach ($payload['push']['changes'] ?? [] as $change) {
            $new = $change['new'] ?? null;

            // `type` is what distinguishes a branch from a tag, and a deletion
            // has no `new` at all — deploying on a branch deletion would check
            // out a branch that no longer exists.
            if (($new['type'] ?? null) === 'branch' && filled($new['name'] ?? null)) {
                return (string) $new['name'];
            }
        }

        return null;
    }

    public function deliveryId(Request $request): ?string
    {
        return $request->header('X-Request-UUID') ?? $request->header('X-Hook-UUID');
    }

    public function secretSource(): string
    {
        return 'generate';
    }

    public function verificationMode(string $secret): string
    {
        return 'signature';
    }
}
