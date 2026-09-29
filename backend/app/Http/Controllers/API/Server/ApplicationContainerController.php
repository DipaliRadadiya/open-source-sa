<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\PullContainerImage;
use App\Actions\Server\Application\UpdateContainerSettings;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateContainerRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;

class ApplicationContainerController extends Controller
{
    /**
     * The credentials the panel generated when it installed this app.
     *
     * Its own endpoint, and that is the point: these are live database passwords,
     * so they are not on `ApplicationResource` where they would ride in every
     * application payload, every list response and every browser cache between
     * here and the page. A caller has to ask for them, and asking is recorded.
     *
     * Read-only. Rotating one means rewriting the compose file AND the credential
     * inside the running database, which is a different operation with a real
     * chance of leaving the two disagreeing — it is not a side effect of looking.
     */
    public function secrets(Application $application, ActivityLogger $log): JsonResponse
    {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        // Logged before returning, not after. A response that is never read still
        // handed the values over.
        $log->log('application.container_secrets_viewed', $application, [
            'keys' => array_keys((array) ($application->docker_secrets ?? [])),
        ]);

        return response()->json([
            'secrets' => (array) ($application->docker_secrets ?? []),
        ]);
    }

    /**
     * Fetch a newer image and recreate the container on it.
     *
     * The update story for a container, and it needs to exist because nothing
     * else does it: `compose up` reuses an image it already has, so a site on a
     * floating tag never moves — which reads as an update button that does
     * nothing. It is also the only way a private image can be updated at all,
     * since a rebuild of the site is the alternative.
     *
     * Synchronous, like the settings endpoint above, and for the same reason: the
     * caller should get the pull's real failure rather than a 202 and a site that
     * quietly stayed where it was. A large image on a slow link is covered by the
     * command timeout, not by a queue.
     */
    public function pull(Application $application, PullContainerImage $action): JsonResponse
    {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        try {
            $pulled = $action->execute($application);
        } catch (ProvisioningFailedException $e) {
            // 422 with the reason, not a 500 with "Server Error". The step and the
            // reference are what support needs; the titled reason is what the
            // person pressing the button needs, and a wrong credential is their
            // problem to fix rather than a server fault to report.
            return response()->json([
                'message' => $e->reason !== null
                    ? __('application.failure_reason.'.$e->reason)
                    : __('errors/application.container_pull_failed'),
                'step' => $e->step,
                'reason' => $e->reason,
                'reference' => $e->reference,
            ], 422);
        }

        return response()->json([
            'application' => ApplicationResource::make($pulled)->resolve(),
        ]);
    }

    /**
     * Change what a container site runs as.
     *
     * Refused for anything that is not a container: the fields have no meaning
     * for a PHP or Node site, and accepting them would store values nothing
     * ever reads — which looks exactly like a feature that works.
     *
     * Synchronous, like the web-root endpoint: rewriting the compose file and
     * recreating one container is seconds, and the caller gets a real failure
     * instead of a 202 and a site that quietly never changed.
     */
    public function update(
        UpdateContainerRequest $request,
        Application $application,
        UpdateContainerSettings $action,
    ): JsonResponse {
        abort_unless(
            $application->serving_profile === 'docker',
            422,
            __('errors/application.not_a_container'),
        );

        return response()->json([
            'application' => ApplicationResource::make(
                $action->execute($application, $request->validated())
            )->resolve(),
        ]);
    }
}
