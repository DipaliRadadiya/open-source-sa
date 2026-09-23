<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Resources\CentralStatusResource;
use App\Services\ActivityLogger;
use App\Services\Server\CentralTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enable, disable and query the central-management connection.
 *
 * These endpoints are for the OSS admin's own settings UI — they require a
 * normal authenticated session (Sanctum). The central panel itself uses
 * the same token via `Authorization: Bearer` with the CentralSystemGuard.
 */
class CentralController extends Controller
{
    public function __construct(
        private CentralTokenManager $tokens,
    ) {}

    /**
     * Generate and store a new central token, replacing any existing one.
     */
    public function enable(Request $request, ActivityLogger $log): JsonResponse
    {
        $result = $this->tokens->enable();

        $log->log('central.connected');

        return response()->json([
            'central_token' => $result['central_token'],
            'message' => __('errors/central.enabled'),
        ], 201);
    }

    /**
     * Return the current connection status. Never returns the raw token.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'central' => CentralStatusResource::make($this->tokens->status())->resolve(),
        ]);
    }

    /**
     * Revoke the current token.
     */
    public function disable(ActivityLogger $log): JsonResponse
    {
        $this->tokens->disable();

        $log->log('central.disconnected');

        return response()->json([
            'message' => __('errors/central.disabled'),
        ]);
    }
}
