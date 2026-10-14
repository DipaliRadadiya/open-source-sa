<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\IssueAdminerSsoToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\AdminerSsoRequest;
use App\Models\Database;
use Illuminate\Http\JsonResponse;

class AdminerSsoController extends Controller
{
    public function __invoke(
        AdminerSsoRequest $request,
        Database $database,
        IssueAdminerSsoToken $action,
    ): JsonResponse {
        $redirectUrl = $action->execute(
            $database,
            $request->validated('database_user_id'),
            $request->user()->id,
            $request->validated('application_id'),
        );

        return response()->json([
            'redirect_url' => $redirectUrl,
        ]);
    }
}
