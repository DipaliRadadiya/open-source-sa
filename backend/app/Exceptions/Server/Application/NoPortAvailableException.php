<?php

namespace App\Exceptions\Server\Application;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every port in the configured range is spoken for.
 *
 * Refused rather than widened. Reaching outside the range would put an
 * application on a port the operator never set aside for one — possibly one
 * something else is about to want.
 */
class NoPortAvailableException extends Exception
{
    public function __construct(public readonly int $from, public readonly int $to)
    {
        parent::__construct("No free port between {$from} and {$to}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('errors/application.no_port_available', ['from' => $this->from, 'to' => $this->to]),
        ], 422);
    }
}
