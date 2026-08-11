<?php

namespace App\Http\Middleware;

use App\Exceptions\Server\CentralTokenException;
use App\Services\Server\CentralTokenManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates central-management system requests using a Bearer token.
 *
 * When the central panel calls the OSS API, it presents the server's token as
 * `Authorization: Bearer <token>`. This middleware validates it and grants
 * system-level access — bypassing the normal user/sanctum auth entirely.
 *
 * Applied only to routes under the `/api/server` group that central uses
 * (e.g. server facts, metrics, app management). Normal user sessions still
 * use Sanctum and the regular auth stack.
 */
class CentralSystemGuard
{
    public function __construct(
        private CentralTokenManager $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization', '');

        if (! str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => __('errors/central.unauthenticated')], 401);
        }

        $token = substr($auth, 7);

        if (blank($token)) {
            return response()->json(['message' => __('errors/central.unauthenticated')], 401);
        }

        try {
            $this->tokens->validate($token);
        } catch (CentralTokenException) {
            return response()->json(['message' => __('errors/central.invalid_token')], 401);
        }

        // Token is valid. Mark the request as central-authenticated so
        // downstream authorisation knows this is a system call.
        $request->attributes->set('central_authenticated', true);

        return $next($request);
    }
}
