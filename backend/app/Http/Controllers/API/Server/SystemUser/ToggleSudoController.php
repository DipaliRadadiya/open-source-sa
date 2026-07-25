<?php

namespace App\Http\Controllers\API\Server\SystemUser;

use App\Actions\Server\SystemUser\ToggleSudo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\ToggleSudoRequest;
use App\Http\Resources\SystemUserResource;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;

class ToggleSudoController extends Controller
{
    public function __invoke(ToggleSudoRequest $request, SystemUser $systemUser, ToggleSudo $action): JsonResponse
    {
        $action->execute($systemUser, $request->boolean('sudo'));

        return response()->json([
            'system_user' => SystemUserResource::make($systemUser->fresh())->resolve(),
        ]);
    }
}
