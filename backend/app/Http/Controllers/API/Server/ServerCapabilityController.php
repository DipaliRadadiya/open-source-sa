<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Server\Capabilities\ServerCapabilities;
use Illuminate\Http\JsonResponse;

class ServerCapabilityController extends Controller
{
    /**
     * What this server is and what it can run.
     *
     * `stack` is how the box was built; `capabilities` is what it can run now.
     * They diverge legitimately — Node installed on a LEMP box — so callers
     * must filter on capabilities, never on the stack.
     */
    public function index(ServerCapabilities $capabilities): JsonResponse
    {
        $record = $capabilities->current();

        return response()->json([
            'capabilities' => [
                'stack' => $record->stack,
                'web_server' => $record->web_server,
                'capabilities' => $record->capabilities ?? [],
                'source' => $record->source,
                'verified_at' => $record->verified_at?->format('d-m-Y H:i:s'),
            ],
        ]);
    }
}
