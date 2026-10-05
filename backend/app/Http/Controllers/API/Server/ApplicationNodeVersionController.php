<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateNodeVersionRequest;
use App\Http\Resources\ApplicationResource;
use App\Jobs\ChangeApplicationNodeVersion;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationNodeVersionController extends Controller
{
    /**
     * Move a site to another installed Node version.
     *
     * 202 and a job, unlike the web root next door: the switch waits for the
     * application to answer on the new version, and a refusal waits again on
     * the way back. `node_version_change` on the resource is where the screen
     * follows it.
     */
    public function update(UpdateNodeVersionRequest $request, Application $application): JsonResponse
    {
        $target = $request->nodeVersion();

        // Already there: nothing to restart, and a 202 for no work would send
        // the screen polling for a change that is never coming.
        if ($target === $application->node_version) {
            return response()->json([
                'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
            ]);
        }

        $application->forceFill([
            'node_version_target' => $target,
            'node_version_failed_reason' => null,
            'node_version_failed_reference' => null,
        ])->save();

        ChangeApplicationNodeVersion::dispatch($application->id, $target);

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ], 202);
    }
}
