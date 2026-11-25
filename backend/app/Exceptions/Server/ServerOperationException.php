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
         * The operation lost a race for a system lock and never started: "try again", not a fault.
         */
        public readonly bool $busy = false,
        /**
         * A lock nobody holds. Separate from `busy`: waiting never helps, the operator must remove a file.
         */
        public readonly bool $staleLock = false,
        /**
         * sudo refused the command: the panel's grant is older than the running code, and the message
         * names the repair command. Answered before the feature's own message, which would hide it.
         */
        public readonly bool $denied = false,
        /**
         * Killed at its timeout. Answered before the feature's own message: nothing is broken, the
         * work was too big for the time allowed.
         */
        public readonly bool $timedOut = false,
    ) {
        parent::__construct();
    }

    /**
     * The `lang` key for the friendly message.
     */
    abstract protected function messageKey(): string;

    /**
     * The stable code for this failure, for a client that branches on it. Overridable because one
     * feature can fail in several distinct ways.
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
                $this->timedOut => 'errors/server.operation_timed_out',
                default => $this->messageKey(),
            }),
            // A stable code so the frontend need not match translated prose.
            'code' => match (true) {
                $this->denied => 'server_sudo_denied',
                $this->staleLock => 'server_stale_lock',
                $this->busy => 'server_busy',
                $this->timedOut => 'server_operation_timed_out',
                default => $this->code(),
            },
            'reference' => $this->reference,
            // 503 for busy (retry later); 500 for a stale lock or refused grant (a human must fix it).
        ], $this->busy && ! $this->staleLock && ! $this->denied ? 503 : 500);
    }
}
