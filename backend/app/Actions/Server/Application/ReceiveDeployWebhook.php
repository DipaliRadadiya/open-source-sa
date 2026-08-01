<?php

namespace App\Actions\Server\Application;

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Git\Webhooks\WebhookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a webhook delivery deploys anything.
 *
 * The shape of the answer matters as much as the answer. A provider disables a
 * hook that keeps failing — GitLab after four consecutive failures — so
 * "authentic, but nothing to do here" must be a success, not an error. Only an
 * unauthentic delivery gets a 4xx.
 *
 * Order is deliberate: identity, then authenticity, then relevance. Nothing is
 * read out of the payload before the signature over it has been checked.
 */
class ReceiveDeployWebhook
{
    public function __construct(
        private WebhookManager $webhooks,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{deployed: bool, reason: string}
     */
    public function execute(Request $request, Application $application): array
    {
        $driver = $this->webhooks->driver((string) $application->webhook_provider);
        $secret = (string) $application->webhook_secret;

        // The raw body, before Laravel has looked at it. The signature covers
        // the exact bytes sent, so re-encoding the parsed array — even into
        // JSON that means the same thing — produces a different digest.
        $body = $request->getContent();

        if ($secret === '' || ! $driver->verify($request, $secret, $body)) {
            // Deliberately not written to the activity log. A rejected delivery
            // is the one thing an unauthenticated caller can cause at will, and
            // a row per attempt would let them flood the user's own history.
            // The provider's delivery log shows them the refusal, and the `code`
            // in the response says why.
            Log::warning('Rejected a deploy webhook with an invalid signature.', [
                'application' => $application->id,
                'provider' => $application->webhook_provider,
                'ip' => $request->ip(),
            ]);

            return ['deployed' => false, 'reason' => 'invalid_signature'];
        }

        $application->forceFill(['webhook_last_delivered_at' => now()])->save();

        if (! $driver->isPush($request)) {
            return ['deployed' => false, 'reason' => 'not_a_push'];
        }

        // A retried delivery is normal — providers retry on a timeout, and the
        // first attempt may well have succeeded. `Cache::add` is the atomic
        // test-and-set; a get-then-put would let two concurrent retries both
        // pass.
        $delivery = $driver->deliveryId($request);

        if ($delivery !== null && ! Cache::add(
            "webhook-delivery:{$application->id}:".hash('sha256', $delivery),
            true,
            (int) config('server.webhooks.delivery_memory', 3600),
        )) {
            return ['deployed' => false, 'reason' => 'duplicate_delivery'];
        }

        $branch = $driver->pushedBranch($this->payload($body));

        // Null is not a wildcard: a tag push, a branch deletion or a payload we
        // could not read all land here, and none of them should deploy.
        if ($branch === null || $branch !== ($application->branch ?: 'main')) {
            return ['deployed' => false, 'reason' => 'other_branch'];
        }

        // Unique-until-processing: a burst of pushes leaves at most one deploy
        // queued behind the one running, rather than twenty.
        //
        // No actor, on purpose. A git push is not a panel user, and every other
        // dispatch site now passes one — so a null here reads as "the system did
        // this", which is true, rather than "we lost track of who did".
        DeployApplication::dispatch($application->id, null);

        $this->activityLogger->log('application.webhook_deployed', $application, [
            'name' => $application->name,
            'branch' => $branch,
        ], actor: null);

        return ['deployed' => true, 'reason' => 'queued'];
    }

    /**
     * The payload, as an array, or empty when it is not JSON we can read.
     *
     * Parsed here rather than trusted from `$request->json()` so that a body
     * which failed to decode is an empty array — the branch check then refuses
     * it — instead of an exception on a route anyone can reach.
     *
     * @return array<string, mixed>
     */
    private function payload(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
