<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\UpdateContainerSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateContainerRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationContainerController extends Controller
{
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
