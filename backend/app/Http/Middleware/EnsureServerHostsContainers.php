<?php

namespace App\Http\Middleware;

use App\Services\Server\Capabilities\ServerCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the Docker screens on a server that hosts no containers.
 *
 * The mirror of {@see EnsureServerManagesDatabases}, and it exists for the
 * same reason that one does: without it the endpoints answer on every server,
 * shell out to a `docker` binary that is not installed, and report its absence
 * as a server error. "Docker is not working" is a bad way to say "this is a
 * LEMP box".
 *
 * Asked of the hosted serving profiles rather than the stack name, so a stack
 * added later gets the right answer without editing this file.
 */
class EnsureServerHostsContainers
{
    public function __construct(private ServerCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->capabilities->hosts('docker'), 409, __('errors/docker.not_a_docker_server'));

        return $next($request);
    }
}
