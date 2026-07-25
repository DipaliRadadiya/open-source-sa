<?php

namespace App\Http\Controllers\API\Server\SystemUser;

use App\Actions\Server\SystemUser\ToggleSshAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\ToggleSshAccessRequest;
use App\Http\Resources\SystemUserResource;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;

class ToggleSshAccessController extends Controller
{
    public function __invoke(ToggleSshAccessRequest $request, SystemUser $systemUser, ToggleSshAccess $action): JsonResponse
    {
        $action->execute($systemUser, $request->boolean('ssh_access'));

        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->fresh())->resolve(),
        ]);
    }
}
