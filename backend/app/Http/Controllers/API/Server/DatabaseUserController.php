<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\ChangeDatabaseUserPassword;
use App\Actions\Server\Database\CreateDatabaseUser;
use App\Actions\Server\Database\DeleteDatabaseUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\StoreDatabaseUserRequest;
use App\Http\Requests\Server\Database\UpdateDatabaseUserPasswordRequest;
use App\Http\Resources\DatabaseUserResource;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Http\JsonResponse;

class DatabaseUserController extends Controller
{
    public function index(Database $database): JsonResponse
    {
        return response()->json([
            'users' => DatabaseUserResource::collection($database->users()->latest()->get())->resolve(),
        ]);
    }

    public function store(StoreDatabaseUserRequest $request, Database $database, CreateDatabaseUser $action): JsonResponse
    {
        $user = $action->execute($database, $request->validated());

        return response()->json([
            'user' => DatabaseUserResource::make($user->load('database'))->resolve(),
        ], 201);
    }

    public function updatePassword(
        UpdateDatabaseUserPasswordRequest $request,
        Database $database,
        DatabaseUser $user,
        ChangeDatabaseUserPassword $action,
    ): JsonResponse {
        $action->execute($user, $request->validated()['password']);

        return response()->json([
            'user' => DatabaseUserResource::make($user->load('database'))->resolve(),
        ]);
    }

    public function destroy(Database $database, DatabaseUser $user, DeleteDatabaseUser $action): JsonResponse
    {
        $action->execute($user);

        return response()->json(null, 204);
    }
}
