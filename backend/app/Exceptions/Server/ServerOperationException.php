<?php

namespace App\Exceptions\Server;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for server-operation failures. Renders a translated, user-friendly
 * message (never raw stderr) plus a reference id the user can quote to
 * support — the raw technical detail lives only in the server-ops log,
 * correlated by that reference.
 */
abstract class ServerOperationException extends Exception
{
    public function __construct(
        public readonly string $reference,
        /**
         * The operation lost a race for a system lock and never started.
         * Reported differently because the answer is "try again", not
         * "something is wrong" — and telling someone their server is broken
         * when it is merely busy sends them debugging a non-problem.
         */
        public readonly bool $busy = false,
    ) {
        parent::__construct();
    }

    /**
     * The `lang` key for the friendly message.
     */
    abstract protected function messageKey(): string;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __($this->busy ? 'errors/server.busy' : $this->messageKey()),
            // A stable code so the frontend can offer a retry button for this
            // case without matching on translated prose.
            'code' => $this->busy ? 'server_busy' : 'server_operation_failed',
            'reference' => $this->reference,
        ], $this->busy ? 503 : 500);
    }
}
