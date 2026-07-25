<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\SystemUser\CreateSystemUser;
use App\Actions\Server\SystemUser\DeleteSystemUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\StoreSystemUserRequest;
use App\Http\Resources\SystemUserResource;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;

class SystemUserController extends Controller
{
    public function index(): JsonResponse
    {
        // Minimal apps on the list (id + name only) — enough to show what the
        // user owns without the full detail.
        $users = SystemUser::query()
            ->with('applications:id,system_user_id,name')
            ->latest()
            ->get();

        return response()->json([
            'system_users' => SystemUserResource::collection($users)->resolve(),
        ]);
    }

    public function store(StoreSystemUserRequest $request, CreateSystemUser $action): JsonResponse
    {
        $systemUser = $action->execute($request->validated());

        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->load('applications'))->resolve(),
        ], 201);
    }

    public function show(SystemUser $systemUser): JsonResponse
    {
        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->load('applications'))->resolve(),
        ]);
    }

    public function destroy(SystemUser $systemUser, DeleteSystemUser $action): JsonResponse
    {
        $action->execute($systemUser);

        return response()->json(null, 204);
    }
}
