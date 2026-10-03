<?php

namespace App\Http\Middleware;

use App\Services\Server\Capabilities\ServerCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the Node screens on a server that runs nothing on the host.
 *
 * Paired with the sidebar filter rather than trusted to it: a hidden screen whose
 * routes still answer is the worse state, because nobody is looking for it. That
 * is the rule {@see EnsureServerManagesDatabases} states about itself and the
 * reason this class exists at all.
 *
 * **Why `runsHostApplications()` and not `hosts('node')`.** A LEMP box hosts no
 * Node *sites*, and still needs Node: it is the build tool for PHP ones, and the
 * panel's own `build_command` placeholder is `npm ci && npm run build`. Refusing
 * these endpoints wherever Node sites are unhosted would break asset builds on
 * every Laravel site on the box. What makes a Docker server different is not that
 * it hosts no Node sites — it is that it hosts no sites at all.
 */
class EnsureServerRunsHostApplications
{
    public function __construct(private ServerCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->capabilities->runsHostApplications(), 409, __('errors/node.not_a_node_server'));

        return $next($request);
    }
}
