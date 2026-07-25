<?php

namespace App\Http\Controllers\API\Admin;

use App\Actions\Admin\CreateUser;
use App\Actions\Admin\DeleteUser;
use App\Actions\Admin\UpdateUser;
use App\Actions\Auth\ChangeUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(ListUsersRequest $request): JsonResponse
    {
        $query = User::query()->with('roles:id,name')->latest();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.is_admin')) {
            $query->where('is_admin', $request->boolean('filter.is_admin'));
        }

        $perPage = (int) $request->input('per_page', 15);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'users' => UserResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(CreateUserRequest $request, CreateUser $action): JsonResponse
    {
        $user = $action->execute($request->validated());

        return response()->json([
            'user' => UserResource::make($user->load('roles:id,name'))->resolve(),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): JsonResponse
    {
        $user = $action->execute($user, $request->validated());

        return response()->json([
            'user' => UserResource::make($user->load('roles:id,name'))->resolve(),
        ]);
    }

    public function destroy(Request $request, User $user, DeleteUser $action): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => [__('user.cannot_delete_self')],
            ]);
        }

        $action->execute($user);

        return response()->json(null, 204);
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user, ChangeUserPassword $action): JsonResponse
    {
        $action->execute($user, $request->validated('password'));

        return response()->json(null, 204);
    }
}
