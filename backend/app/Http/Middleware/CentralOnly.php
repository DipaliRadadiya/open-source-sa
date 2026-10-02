<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes only the central panel may call, and nobody else may see.
 *
 * Passes a request CentralSystemGuard signed in with this server's central
 * token, and answers anyone else — an administrator included — with the same
 * 404 a route that does not exist gives. These routes drive paid addons the
 * OSS panel does not offer itself, so to everyone but Central they are not
 * there.
 *
 * This is not what stops a server that has not bought an addon: the addon
 * binary asks the vendor's licence API on every run, and an administrator
 * can mint their own central token. It keeps the panel's surface honest.
 */
class CentralOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get('central_authenticated') === true, 404);

        return $next($request);
    }
}
