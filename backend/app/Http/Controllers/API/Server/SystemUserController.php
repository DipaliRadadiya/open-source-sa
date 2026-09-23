<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\SystemUser\CreateSystemUser;
use App\Actions\Server\SystemUser\DeleteSystemUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\IndexSystemUsersRequest;
use App\Http\Requests\Server\SystemUser\StoreSystemUserRequest;
use App\Http\Resources\SystemUserResource;
use App\Models\SystemUser;
use App\Services\Server\Settings\SecuritySettings;
use App\Support\ListSearch;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;

class SystemUserController extends Controller
{
    public function index(IndexSystemUsersRequest $request, SecuritySettings $security): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));

        // Minimal apps on the list (id + name only) — enough to show what the
        // user owns without the full detail.
        $users = SystemUser::query()
            ->with('applications:id,system_user_id,name')
            ->when($search !== '', fn ($query) => ListSearch::apply($query, $search, ['username']));

        $users = ListSort::apply($users, $request->validated('sort'), IndexSystemUsersRequest::SORTS)
            ->paginate($request->validated('per_page', IndexSystemUsersRequest::PER_PAGE));

        return response()->json([
            'system_users' => SystemUserResource::collection($users->items())->resolve(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                // Server-wide, so here rather than on every row: whether the
                // `ssh_access` toggle is enforced at all — see
                // SecuritySettings::sshAccessEnforced(). Null = unknown.
                'ssh_access_enforced' => $security->sshAccessEnforced(),
            ],
        ]);
    }

    public function store(StoreSystemUserRequest $request, CreateSystemUser $action): JsonResponse
    {
        $systemUser = $action->execute($request->validated());

        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->load('applications'))->resolve(),
        ], 201);
    }

    public function show(SystemUser $systemUser, SecuritySettings $security): JsonResponse
    {
        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->load('applications'))->resolve(),
            'meta' => ['ssh_access_enforced' => $security->sshAccessEnforced()],
        ]);
    }

    public function destroy(SystemUser $systemUser, DeleteSystemUser $action): JsonResponse
    {
        $action->execute($systemUser);

        return response()->json(null, 204);
    }
}
