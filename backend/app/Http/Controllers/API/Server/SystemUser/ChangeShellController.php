<?php

namespace App\Http\Controllers\API\Server\SystemUser;

use App\Actions\Server\SystemUser\ChangeShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\ChangeShellRequest;
use App\Http\Resources\SystemUserResource;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;

class ChangeShellController extends Controller
{
    public function __invoke(ChangeShellRequest $request, SystemUser $systemUser, ChangeShell $action): JsonResponse
    {
        $action->execute($systemUser, $request->validated('shell'));

        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->fresh())->resolve(),
        ]);
    }
}
