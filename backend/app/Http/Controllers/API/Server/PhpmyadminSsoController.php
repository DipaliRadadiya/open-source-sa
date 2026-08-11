<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\IssuePhpmyadminSsoToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\PhpmyadminSsoRequest;
use App\Models\Database;
use Illuminate\Http\JsonResponse;

class PhpmyadminSsoController extends Controller
{
    public function __invoke(
        PhpmyadminSsoRequest $request,
        Database $database,
        IssuePhpmyadminSsoToken $action,
    ): JsonResponse {
        $redirectUrl = $action->execute(
            $database,
            $request->validated('database_user_id'),
            $request->user()->id,
        );

        return response()->json([
            'redirect_url' => $redirectUrl,
        ]);
    }
}
