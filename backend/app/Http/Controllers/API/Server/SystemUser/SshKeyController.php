<?php

namespace App\Http\Controllers\API\Server\SystemUser;

use App\Actions\Server\SystemUser\AddSshKey;
use App\Actions\Server\SystemUser\RemoveSshKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\StoreSshKeyRequest;
use App\Http\Resources\SshKeyResource;
use App\Models\SshKey;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;

class SshKeyController extends Controller
{
    public function index(SystemUser $systemUser): JsonResponse
    {
        return response()->json([
            'ssh_keys' => SshKeyResource::collection($systemUser->sshKeys()->latest()->get())->resolve(),
        ]);
    }

    public function store(StoreSshKeyRequest $request, SystemUser $systemUser, AddSshKey $action): JsonResponse
    {
        $key = $action->execute($systemUser, $request->validated());

        return response()->json([
            'ssh_key' => SshKeyResource::make($key)->resolve(),
        ], 201);
    }

    public function destroy(SystemUser $systemUser, SshKey $sshKey, RemoveSshKey $action): JsonResponse
    {
        abort_unless($sshKey->system_user_id === $systemUser->id, 404);

        $action->execute($systemUser, $sshKey);

        return response()->json(null, 204);
    }
}
