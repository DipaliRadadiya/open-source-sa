<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\UpdateApplicationBasicAuth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateBasicAuthRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationSecurityController extends Controller
{
    public function update(
        UpdateBasicAuthRequest $request,
        Application $application,
        UpdateApplicationBasicAuth $action,
    ): JsonResponse {
        $action->execute(
            $application,
            $request->enabled(),
            $request->enabled() ? $request->username() : null,
            $request->enabled() ? $request->password() : null,
        );

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser']))->resolve(),
        ]);
    }
}
