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
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => [__('auth.cannot_impersonate_self')],
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'user' => [__('auth.cannot_impersonate_admin')],
            ]);
        }

        $result = $action->execute($request->user(), $user);

        return response()->json([
            'user' => UserResource::make($result['user'])->resolve(),
            'token' => $result['token'],
            'impersonated_by' => [
                'id' => $request->user()->id,
                'username' => $request->user()->username,
            ],
        ], 201);
    }
}
