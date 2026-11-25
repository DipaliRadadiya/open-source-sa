<?php

namespace App\Http\Middleware;

use App\Exceptions\Server\CentralTokenException;
use App\Services\Central\CentralUser;
use App\Services\Server\CentralTokenManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs the central panel in when it presents the server's token.
 *
 * An authenticator, not a gate. It runs ahead of `auth:sanctum` on every route and either:
 *
 *   - a valid central token   -> sign in as the machine account and continue
 *   - anything else, or none  -> touch nothing, and let `auth:sanctum` decide
 *
 * So a wrong token gets the ordinary 401 and nothing becomes more permissive. Signing in as a
 * real user keeps `permission:` middleware and policies working, and since it is the
 * {@see CentralUser} machine account, the activity log attributes the vendor's actions to the
 * integration. Access ends on disconnect: the token is compared on every request and then nulled.
 */
class CentralSystemGuard
{
    public function __construct(
        private CentralTokenManager $tokens,
        private CentralUser $centralUser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearer($request);

        // No token, or someone else's (Sanctum tokens are `Bearer` too): must be completely inert.
        if ($token === null || ! $this->isCentral($token)) {
            return $next($request);
        }

        // `setUser`, not `login`: a session would outlive the token, so a revoked token would
        // keep working. Sanctum reads `web`'s user before it looks at tokens.
        Auth::guard('web')->setUser($this->centralUser->ensure());

        // Lets anything treat a vendor call differently from a person's.
        $request->attributes->set('central_authenticated', true);

        return $next($request);
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return blank($token) ? null : $token;
    }

    /**
     * Comparison is delegated so the constant-time check lives in one place.
     */
    private function isCentral(string $token): bool
    {
        try {
            $this->tokens->validate($token);

            return true;
        } catch (CentralTokenException) {
            return false;
        }
    }
}
