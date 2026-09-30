<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\ConfigureApplicationWebhook;
use App\Actions\Server\Application\ReceiveDeployWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateApplicationWebhookRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\Git\Webhooks\WebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationWebhookController extends Controller
{
    /**
     * What each provider needs from the user, and which way its secret travels.
     * Data rather than three hardcoded sets of instructions in the frontend.
     */
    public function providers(WebhookManager $webhooks): JsonResponse
    {
        return response()->json(['webhook_providers' => $webhooks->catalog()]);
    }

    public function update(
        UpdateApplicationWebhookRequest $request,
        Application $application,
        ConfigureApplicationWebhook $configure,
    ): JsonResponse {
        $application = $configure->execute($application, $request->validated());

        return response()->json([
            'application' => ApplicationResource::make($application->load('systemUser'))->resolve(),
            // Whether the panel added the webhook to the repository itself, and
            // if not, why — so the screen can say "done" or show the URL and
            // secret to paste. Null when deploy-on-push was switched off —
            // unless the provider refused to remove the hook, which the user
            // then has to delete by hand (`status: removal_refused`).
            'webhook_registration' => $configure->registration === null ? null : [
                ...$configure->registration,
                'message' => $configure->registration['reason'] === null
                    ? null
                    : __('application.webhook_registration.'.$configure->registration['reason']),
            ],
        ]);
    }

    /**
     * The provider calling in. **Unauthenticated by design** — the signature
     * over the body is the credential, and no session or token exists on a
     * request from GitHub.
     *
     * Every authentic delivery answers 2xx, including the ones that deploy
     * nothing. Providers disable a hook that keeps failing (GitLab after four
     * consecutive failures), so "understood, nothing to do" must not look like
     * a fault. Only an unauthentic delivery gets a 401.
     */
    public function receive(
        Request $request,
        string $identifier,
        ReceiveDeployWebhook $receive,
    ): JsonResponse {
        $application = Application::query()
            ->where('webhook_identifier', $identifier)
            ->firstOrFail();

        // Deploy-on-push switched off in the panel while the hook still sits
        // in the repository. A stranger — no valid signature — gets the same
        // 404 as an identifier that never existed, so this cannot be used to
        // learn which sites exist. The provider itself is told plainly, so
        // its delivery log says why nothing deployed instead of "not found".
        // 410 rather than a 2xx: the provider should count it as failing
        // (GitLab disables a hook after a few failures), which is right for
        // a hook nobody wants any more.
        if (! $application->webhook_enabled) {
            abort_unless($receive->authentic($request, $application), 404, __('errors/http.not_found'));

            return response()->json([
                'deployed' => false,
                'reason' => 'webhook_disabled',
                'message' => __('application.webhook_delivery.disabled'),
            ], 410);
        }

        $result = $receive->execute($request, $application);

        return response()->json(
            ['deployed' => $result['deployed'], 'reason' => $result['reason']],
            $result['reason'] === 'invalid_signature' ? 401 : 202,
        );
    }
}
