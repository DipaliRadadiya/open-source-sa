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
        /**
         * sudo refused the command outright. Neither a busy server nor a
         * broken one: the panel's grant is older than the code running on it,
         * and the message says which command repairs it. Answered before the
         * feature's own message, because "changing the web root failed on the
         * server" describes a fault that does not exist and hides one that
         * does.
         */
        public readonly bool $denied = false,
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
                $this->denied => 'errors/server.sudo_denied',
                $this->staleLock => 'errors/server.stale_lock',
                $this->busy => 'errors/server.busy',
                default => $this->messageKey(),
            }),
            // A stable code so the frontend can offer a retry button for this
            // case without matching on translated prose.
            'code' => match (true) {
                $this->denied => 'server_sudo_denied',
                $this->staleLock => 'server_stale_lock',
                $this->busy => 'server_busy',
                default => $this->code(),
            },
            'reference' => $this->reference,
            // 503 for busy (come back later); 500 for a stale lock and for a
            // refused grant, because both are faults on this server that need
            // a human rather than a retry.
        ], $this->busy && ! $this->staleLock && ! $this->denied ? 503 : 500);
    }
}
