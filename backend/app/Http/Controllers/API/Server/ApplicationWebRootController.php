<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\UpdateApplicationWebRoot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateWebRootRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationWebRootController extends Controller
{
    /**
     * Change which directory of the site is served.
     *
     * Synchronous on purpose: creating the directory, rewriting the vhost,
     * testing it and reloading takes well under a second, and the caller
     * gets a real pass or a real failure instead of a 202 and a site that
     * quietly never moved.
     */
    public function update(UpdateWebRootRequest $request, Application $application, UpdateApplicationWebRoot $action): JsonResponse
    {
        $action->execute($application, $request->webRoot());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ]);
    }
}
