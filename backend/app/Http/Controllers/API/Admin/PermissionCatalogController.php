<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Services\ActivityLogger;
use App\Services\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class PermissionCatalogController extends Controller
{
    /**
     * The full permission catalog (every permission, ordered) — feeds the
     * role create/edit form's checkbox list. Unlike GET /permissions (the
     * caller's own effective grants), this is the complete menu of what can
     * be granted, independent of the admin's own permissions.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('order')->get();

        return response()->json([
            'permissions' => PermissionResource::collection($permissions)->resolve(),
        ]);
    }

    /**
     * Re-sync the permission catalog from code (the seeder) and re-sync the
     * protected Administrator role — idempotent. A UI shortcut for what the
     * deploy runbook does, useful after new permissions are added. Returns
     * the refreshed catalog.
     */
    public function sync(PermissionCatalog $catalog, ActivityLogger $activityLogger): JsonResponse
    {
        $catalog->sync();

        $permissions = Permission::query()->orderBy('order')->get();

        $activityLogger->log('permission.synced', properties: ['count' => $permissions->count()]);

        return response()->json([
            'permissions' => PermissionResource::collection($permissions)->resolve(),
            'synced' => $permissions->count(),
        ]);
    }
}
