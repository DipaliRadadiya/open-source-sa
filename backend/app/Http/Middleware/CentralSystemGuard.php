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
 * An authenticator, not a gate — and the distinction is the whole design.
 *
 * A gate refuses everything without a central token, which is why this could
 * only ever be applied to routes central alone uses. Central uses the whole
 * API, so instead this runs ahead of `auth:sanctum` on every route and does
 * exactly one of two things:
 *
 *   - a valid central token   -> sign in as the machine account and continue
 *   - anything else, or none  -> touch nothing, and let `auth:sanctum` decide
 *
 * The second branch is what makes this safe to run everywhere. A wrong token
 * is not rejected here with a central-specific message; it is simply not a
 * central token, and the request gets the ordinary 401 it would have got
 * before this middleware existed. Nothing becomes more permissive than it was.
 *
 * Signing in as a real user rather than setting a flag is what makes the rest
 * of the application work unchanged: `permission:` middleware, policies and
 * the activity log all see an authenticated administrator. And because that
 * administrator is {@see CentralUser} — a machine account, not the person who
 * pressed connect — the log attributes what the vendor did to the
 * integration, which is the question a customer asks afterwards.
 *
 * Access ends the moment the admin disconnects: the token is compared against
 * the stored value on every request, and disconnecting nulls it.
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

        // No token at all, or one that belongs to somebody else. Sanctum's
        // own tokens arrive as `Bearer` too, so this branch is the common
        // case on nearly every request and has to be completely inert.
        if ($token === null || ! $this->isCentral($token)) {
            return $next($request);
        }

        // `setUser`, not `login`: this authenticates the current request and
        // nothing beyond it. `login()` writes the session, and a session
        // outlives the token — a revoked or replaced token kept working for
        // any caller still holding the cookie, which is the one promise this
        // feature has to keep. Sanctum reads `web`'s user before it looks at
        // tokens, so setting it here is all the rest of the stack needs.
        Auth::guard('web')->setUser($this->centralUser->ensure());

        // Kept for anything that wants to treat a vendor call differently
        // from a person's — the log line, a future confirmation prompt.
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
