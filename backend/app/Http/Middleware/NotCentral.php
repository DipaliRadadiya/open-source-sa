<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes the central panel may not call (bug #50).
 *
 * Central signs in as an administrator, so on its own it could reset an
 * administrator's password, create a new administrator or hand out roles —
 * everything needed to take the panel over with one leaked key. Who may run
 * this server is decided here, by the people who run it. Reading users and
 * roles is still allowed.
 */
class NotCentral
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->attributes->get('central_authenticated') === true, 403, __('central.errors.not_for_central'));

        return $next($request);
    }
}
