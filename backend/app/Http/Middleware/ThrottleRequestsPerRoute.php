<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with one counter per route instead of one per user.
 *
 * Laravel keys a numeric throttle by the user alone
 * (`resolveRequestSignature()` is the user id), so every `throttle:N,M` route
 * shares a single counter and only the ceiling differs. 81 routes here use
 * one — from `throttle:2,1` to `throttle:1200,1` for upload chunks — so a
 * user who uploaded a file, or created and tested a storage destination, was
 * told "Too Many Attempts" on their first "Run backup now" (found live,
 * 2026-09-23). The limits were written per action; this makes them mean that.
 *
 * Named limiters (`throttle:api`, `throttle:login`) do not come through here —
 * they are keyed by their own definition and are unchanged.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        // The route's URI pattern, not the request path: `/backups/7` and
        // `/backups/8` are one action with one limit, not two.
        return parent::resolveRequestSignature($request)
            .'|'.$request->method().'|'.($route?->uri() ?? $request->path());
    }
}
