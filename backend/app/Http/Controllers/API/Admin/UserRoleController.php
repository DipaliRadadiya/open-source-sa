<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\AssignRoleToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function update(AssignRoleRequest $request, User $user, AssignRoleToUser $action): JsonResponse
    {
        $action->execute($user, $request->validated('role_id'));

        return response()->json(null, 204);
    }
}
