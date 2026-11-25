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
     * The full permission catalog (every permission, ordered) for the role form's checkbox list.
     * Unlike GET /permissions (the caller's own grants), it is everything that can be granted.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->catalogPayload());
    }

    /**
     * Re-sync the permission catalog from code and the protected Administrator role (idempotent).
     * Returns the refreshed catalog.
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
     * `permissions` is the flat ordered list. `groups` is the same rows bucketed by level and
     * sub-level, with localized section titles, for the form's section headers.
     * `access_levels` names the three states a grant can hold.
     *
     * @return array{permissions: array<int, mixed>, groups: array<int, mixed>, access_levels: array<int, mixed>}
     */
    private function catalogPayload(): array
    {
        $permissions = Permission::query()->orderBy('order')->get();
        $resolved = PermissionResource::collection($permissions)->resolve();

        $groups = collect($resolved)
            // Keyed by both: `logs` exists at server and application level as two permissions.
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
