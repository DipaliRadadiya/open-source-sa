<?php

namespace App\Http\Middleware;

use App\Services\Server\Capabilities\ServerCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the PHP screens on a server that does not serve PHP sites.
 *
 * The third of these, after {@see EnsureServerManagesDatabases} and
 * {@see EnsureServerHostsContainers}, and it exists for the reason the first
 * one states about itself: **gating, not hiding.** The sidebar stops offering
 * the PHP screen on a Docker box, and if that were the only change every
 * endpoint would still answer — a hidden button whose route works is a worse
 * state than a visible one, because nobody is looking for it.
 *
 * **`hosts('php')`, never `can('php')`.** The distinction is load-bearing and
 * the reason this class is three lines rather than one: `can('php')` asks
 * whether PHP is installed, which is true on a Docker box and always will be
 * because the panel is itself a Laravel application. `hosts('php')` asks
 * whether the box will serve PHP *sites*, which is the decision the stack made.
 * Gating on the former would refuse these routes nowhere; gating on the stack
 * NAME would need editing every time a stack is added.
 *
 * A server with no recorded stack — migrated in from another panel — lands on
 * `ServerCapabilities::DEFAULT_PROFILES`, which includes `php` deliberately.
 * That box is certainly hosting something, and the permissive answer is the
 * only safe one: refusing PHP management on a working LEMP server because
 * nobody wrote a row is a far worse failure than showing a tab on a Docker box.
 */
class EnsureServerHostsPhpSites
{
    public function __construct(private ServerCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->capabilities->hosts('php'), 409, __('errors/php.not_a_php_server'));

        return $next($request);
    }
}
