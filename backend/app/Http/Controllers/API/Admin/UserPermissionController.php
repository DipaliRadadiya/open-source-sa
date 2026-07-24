<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\UpdateUserPermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserPermissionsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserPermissionController extends Controller
{
    public function update(UpdateUserPermissionsRequest $request, User $user, UpdateUserPermissions $action): JsonResponse
    {
        $action->execute($user, $request->validated('permissions'));

        return response()->json(null, 204);
    }
}
