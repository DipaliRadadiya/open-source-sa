<?php

namespace App\Http\Middleware;

use App\Services\Server\Capabilities\ServerCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses database management on a server that does not host database-backed
 * applications.
 *
 * A Docker box runs containers; an application that wants a database brings
 * one as a container of its own. The panel manages no engine there, and the
 * panel's own data is SQLite regardless.
 *
 * Applied as middleware rather than checked in twenty-nine controller actions,
 * for the reason the sudoers list is one list: a check repeated per route is a
 * check somebody forgets to add to the thirtieth.
 *
 * **Gating, not hiding.** The frontend also stops showing the Databases screen
 * on such a server, and if that were the only change then every endpoint would
 * still answer — a hidden button whose route works is a worse state than a
 * visible one, because nobody is looking for it.
 */
class EnsureServerManagesDatabases
{
    public function __construct(private ServerCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Asked of the hosted profiles rather than the stack name, so a stack
        // added later gets the right answer without editing this file.
        foreach (['php', 'node'] as $profile) {
            if ($this->capabilities->hosts($profile)) {
                return $next($request);
            }
        }

        abort(409, __('errors/server.databases_not_managed'));
    }
}
