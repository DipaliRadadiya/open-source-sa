<?php

namespace App\Exceptions;

use App\Exceptions\Server\ServerOperationException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A feature refusing a request for a reason the user can act on.
 *
 * Distinct from {@see ServerOperationException}, which
 * is for a command that failed on the server: that one carries a log
 * reference and one fixed message key, because the user cannot be told what
 * actually went wrong. This one carries the message itself, because the
 * reason *is* the answer — "phpMyAdmin is not deployed", "this database has
 * no users" — and a single key per exception class could not express it.
 *
 * `feature` names the area for the log and for the response `code`, matching
 * the `<feature>.<action>_failed` convention the activity log already uses.
 */
class FeatureException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $feature,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            // Stable, untranslated, so the frontend can branch on it without
            // matching prose.
            'code' => "{$this->feature}_request_refused",
        ], $this->status);
    }
}
