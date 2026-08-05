<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\PermissionFixer;
use Illuminate\Http\JsonResponse;

class ApplicationFileController extends Controller
{
    public function fixPermissions(
        Application $application,
        PermissionFixer $fixer,
        ActivityLogger $activity,
    ): JsonResponse {
        $fixer->fix($application);

        $activity->log('application.permissions_fixed', $application, [
            'name' => $application->name,
        ]);

        return response()->json(['fixed' => true]);
    }
}
