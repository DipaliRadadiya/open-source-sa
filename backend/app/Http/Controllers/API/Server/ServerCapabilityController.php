<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Server\Applications\DnsVerifier;
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
    public function index(ServerCapabilities $capabilities, DnsVerifier $dns): JsonResponse
    {
        $record = $capabilities->current();

        return response()->json([
            'capabilities' => [
                'stack' => $record->stack,
                'web_server' => $record->web_server,
                'capabilities' => $record->capabilities ?? [],
                'source' => $record->source,
                'verified_at' => $record->verified_at?->format('d-m-Y H:i:s'),
                // This box's own address, so the create form can build the
                // temporary `<name>.<ip>.nip.io` hostname it offers. Detected
                // from the local route and cached, and null on a box where that
                // failed — the form has to be able to say "we could not work
                // out this server's address" rather than offering a name that
                // resolves nowhere.
                'server_ip' => $dns->serverIp(),
                'temporary_domain_suffix' => (string) config('server.temporary_domain_suffix', 'nip.io'),
            ],
        ]);
    }
}
