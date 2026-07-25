<?php

namespace App\Http\Controllers\API\Server\SystemUser;

use App\Actions\Server\SystemUser\SetSystemUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SystemUser\SetPasswordRequest;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;

class SetPasswordController extends Controller
{
    public function __invoke(SetPasswordRequest $request, SystemUser $systemUser, SetSystemUserPassword $action): JsonResponse
    {
        $action->execute($systemUser, $request->validated('password'));

        return response()->json(null, 204);
    }
}
