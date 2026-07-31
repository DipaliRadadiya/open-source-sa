<?php

namespace App\Exceptions\Server\WebServer;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OpenLiteSpeed's shared config has no listener block to map a site into.
 *
 * Refused rather than repaired. A `map` is only legal inside a `listener`, and
 * writing one would mean guessing which address and port this server is meant
 * to answer on — a guess that either does nothing or takes over a port
 * something else owns.
 */
class OlsListenerNotFoundException extends Exception
{
    public function __construct(public readonly string $listener)
    {
        parent::__construct("OpenLiteSpeed listener [{$listener}] not found in the shared configuration.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('errors/web-server.ols_listener_missing', ['listener' => $this->listener]),
        ], 500);
    }
}
