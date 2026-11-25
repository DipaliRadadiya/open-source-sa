<?php

namespace App\Actions\Server\Application;

use App\Enums\DeploymentTrigger;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Git\Webhooks\WebhookManager;
use App\Services\Server\Applications\DeployQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a webhook delivery deploys anything.
 *
 * "Authentic, but nothing to do here" must be a success: providers disable a hook that keeps
 * failing. Only an unauthentic delivery gets a 4xx.
 *
 * Order is deliberate: identity, then authenticity, then relevance. Nothing is read from the
 * payload before its signature has been checked.
 */
class ReceiveDeployWebhook
{
    public function __construct(
        private WebhookManager $webhooks,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Whether this delivery really comes from the site's webhook provider: signed (or, for
     * GitLab's legacy scheme, tokened) with the stored secret.
     *
     * Also asked when deploy-on-push is off: the secret is kept, so an authentic delivery can be
     * told "this is off" while anyone else still gets the plain 404.
     */
    public function authentic(Request $request, Application $application): bool
    {
        $secret = (string) $application->webhook_secret;
        $provider = (string) $application->webhook_provider;

        if ($secret === '' || ! $this->webhooks->supports($provider)) {
            return false;
        }

        return $this->webhooks->driver($provider)->verify($request, $secret, $request->getContent());
    }

    /**
     * @return array{deployed: bool, reason: string}
     */
    public function execute(Request $request, Application $application): array
    {
        $driver = $this->webhooks->driver((string) $application->webhook_provider);
        // The raw body: the signature covers the exact bytes sent, so re-encoding the parsed
        // array would give a different digest.
        $body = $request->getContent();

        if (! $this->authentic($request, $application)) {
            // Not written to the activity log: an unauthenticated caller could flood it at will.
            Log::warning('Rejected a deploy webhook with an invalid signature.', [
                'application' => $application->id,
                'provider' => $application->webhook_provider,
                'ip' => $request->ip(),
            ]);

            return ['deployed' => false, 'reason' => 'invalid_signature'];
        }

        $application->forceFill(['webhook_last_delivered_at' => now()])->save();

        // A site with no repository would queue a deploy that can only fail. Checked after the
        // signature so an unauthenticated caller learns nothing about the site.
        if ($application->site_type !== 'git') {
            return ['deployed' => false, 'reason' => 'not_a_git_application'];
        }

        // Account disconnected: no credential or URL to deploy with. A success, not an error,
        // or the provider disables the hook.
        if ($application->gitAccountMissing()) {
            return ['deployed' => false, 'reason' => 'git_account_missing'];
        }

        if (! $driver->isPush($request)) {
            return ['deployed' => false, 'reason' => 'not_a_push'];
        }

        // Providers retry on a timeout. `Cache::add` is atomic; get-then-put would let two
        // concurrent retries both pass.
        $delivery = $driver->deliveryId($request);

        if ($delivery !== null && ! Cache::add(
            "webhook-delivery:{$application->id}:".hash('sha256', $delivery),
            true,
            (int) config('server.webhooks.delivery_memory', 3600),
        )) {
            return ['deployed' => false, 'reason' => 'duplicate_delivery'];
        }

        $branch = $driver->pushedBranch($this->payload($body));

        // Null is not a wildcard: a tag push, branch deletion or unreadable payload must not deploy.
        if ($branch === null || $branch !== ($application->branch ?: 'main')) {
            return ['deployed' => false, 'reason' => 'other_branch'];
        }

        // No actor on purpose: a git push is not a panel user, so it reads as System.
        // A push that lands while a deploy is still waiting joins it (see DeployQueue).
        app(DeployQueue::class)->queue($application, DeploymentTrigger::Webhook, null);

        $this->activityLogger->log('application.webhook_deployed', $application, [
            'name' => $application->name,
            'branch' => $branch,
        ], actor: null);

        return ['deployed' => true, 'reason' => 'queued'];
    }

    /**
     * The payload as an array, or empty when it is not readable JSON. Not `$request->json()`: a
     * body that fails to decode must be refused by the branch check, not throw on a public route.
     *
     * @return array<string, mixed>
     */
    private function payload(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
