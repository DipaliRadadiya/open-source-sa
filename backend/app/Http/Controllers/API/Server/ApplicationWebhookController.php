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
            // secret to paste. Null when deploy-on-push was switched off.
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
        // A disabled webhook and an identifier that never existed answer
        // identically: anything else would confirm which applications exist.
        $application = Application::query()
            ->where('webhook_identifier', $identifier)
            ->where('webhook_enabled', true)
            ->firstOrFail();

        $result = $receive->execute($request, $application);

        return response()->json(
            ['deployed' => $result['deployed'], 'reason' => $result['reason']],
            $result['reason'] === 'invalid_signature' ? 401 : 202,
        );
    }
}
