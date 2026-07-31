<?php

namespace App\Services\Git\Webhooks;

use App\Contracts\GitWebhook;
use Illuminate\Http\Request;

/**
 * GitHub: HMAC-SHA256 of the raw body, hex, in `X-Hub-Signature-256`.
 *
 * The header name is the whole trap here. Bitbucket signs identically and calls
 * its header `X-Hub-Signature` — the same name minus the suffix — so a verifier
 * written for one and pointed at the other never matches, with no error to
 * explain why. The two are separate classes for that reason alone.
 */
class GithubWebhook implements GitWebhook
{
    public function verify(Request $request, string $secret, string $body): bool
    {
        return HmacSignature::matches(
            $request->header('X-Hub-Signature-256'),
            $secret,
            $body,
        );
    }

    public function isPush(Request $request): bool
    {
        return $request->header('X-GitHub-Event') === 'push';
    }

    public function pushedBranch(array $payload): ?string
    {
        return Ref::branch($payload['ref'] ?? null);
    }

    public function deliveryId(Request $request): ?string
    {
        return $request->header('X-GitHub-Delivery');
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
