<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckPermissionRequest;
use App\Http\Requests\ListPermissionsRequest;
use App\Models\Application;
use App\Services\VisiblePermissions;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(private VisiblePermissions $permissions) {}

    public function index(ListPermissionsRequest $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->permissions->for(
                $request->user(),
                $request->string('level')->toString() ?: null,
                $request->filled('application_id')
                    ? Application::find($request->integer('application_id'))
                    : null,
            ),
        ]);
    }

    public function check(CheckPermissionRequest $request): JsonResponse
    {
        return response()->json([
            'permissions' => $this->permissions->for($request->user(), $request->string('level')->toString()),
        ]);
    }
}
