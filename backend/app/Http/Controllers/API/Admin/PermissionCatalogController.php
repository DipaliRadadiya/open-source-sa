<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\AccessLevel;
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
        return response()->json($this->catalogPayload());
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

        $payload = $this->catalogPayload();

        $activityLogger->log('permission.synced', properties: ['count' => count($payload['permissions'])]);

        return response()->json($payload + ['synced' => count($payload['permissions'])]);
    }

    /**
     * The catalog in the three shapes the role form needs at once.
     *
     * `permissions` is the flat ordered list, unchanged. `groups` is the same
     * rows already bucketed by level and sub-level, because the form renders
     * section headers and a select-all per section — grouping it here means
     * one implementation instead of one per frontend, and the section titles
     * come back localized rather than being invented client-side.
     * `access_levels` names the three states a grant can hold.
     *
     * @return array{permissions: array<int, mixed>, groups: array<int, mixed>, access_levels: array<int, mixed>}
     */
    private function catalogPayload(): array
    {
        $permissions = Permission::query()->orderBy('order')->get();
        $resolved = PermissionResource::collection($permissions)->resolve();

        $groups = collect($resolved)
            // Keyed by both: `logs` exists at server and application level as
            // two different permissions, and merging them into one section
            // would offer a single control over two unrelated grants.
            ->groupBy(fn (array $permission): string => $permission['level'].'|'.$permission['sub_level'])
            ->map(fn ($items, string $key): array => [
                'level' => explode('|', $key)[0],
                'sub_level' => explode('|', $key)[1],
                'sub_level_title' => $items->first()['sub_level_title'],
                'permissions' => $items->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'permissions' => $resolved,
            'groups' => $groups,
            'access_levels' => AccessLevel::catalog(),
        ];
    }
}
