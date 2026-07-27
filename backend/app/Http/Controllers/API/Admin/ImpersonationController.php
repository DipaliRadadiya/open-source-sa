<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\ImpersonateUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating a user. Admin-only (route is under
     * can:access-admin). Blocks self and admin→admin.
     */
    public function __invoke(Request $request, User $user, ImpersonateUser $action): JsonResponse
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages([
                'user' => [__('auth.cannot_impersonate_self')],
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'user' => [__('auth.cannot_impersonate_admin')],
            ]);
        }

        // Session-based: logs the target in on the cookie session, no token.
        $action->execute($admin, $user);

        return response()->json([
            'user' => UserResource::make($user->load('roles:id,name'))->resolve(),
            'impersonated_by' => [
                'id' => $admin->id,
                'username' => $admin->username,
            ],
        ], 201);
    }
}
