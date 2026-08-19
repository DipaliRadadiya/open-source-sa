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
        /**
         * A lock nobody holds. Separated from `busy` because "try again in a
         * moment" is advice that can never come true here — the operator has
         * to remove a file, and telling them to wait sends them in circles.
         */
        public readonly bool $staleLock = false,
    ) {
        parent::__construct();
    }

    /**
     * The `lang` key for the friendly message.
     */
    abstract protected function messageKey(): string;

    /**
     * The stable code for this failure, for a client that branches on it.
     *
     * Overridable because one feature's "the server operation failed" can be
     * several different failures — a cron job write touches seven privileged
     * paths — and a single code for all of them tells the user only that
     * something went wrong, which they already knew.
     */
    protected function code(): string
    {
        return 'server_operation_failed';
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __(match (true) {
                $this->staleLock => 'errors/server.stale_lock',
                $this->busy => 'errors/server.busy',
                default => $this->messageKey(),
            }),
            // A stable code so the frontend can offer a retry button for this
            // case without matching on translated prose.
            'code' => match (true) {
                $this->staleLock => 'server_stale_lock',
                $this->busy => 'server_busy',
                default => $this->code(),
            },
            'reference' => $this->reference,
            // 503 for busy (come back later); 500 for a stale lock, because
            // it is a fault on this server that needs a human, not a retry.
        ], $this->busy && ! $this->staleLock ? 503 : 500);
    }
}
