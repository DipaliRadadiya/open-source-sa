<?php

namespace App\Exceptions\Server\Log;

use App\Exceptions\Server\ServerOperationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A log the panel was asked for and could not read.
 *
 * Distinct from the 404 for a source that does not exist and the 403 for one
 * the panel has no permission to open: both of those are answers. This is the
 * case where there is no answer, and the alternative to saying so was returning
 * an empty log — which reads as "nothing has happened here", the opposite of
 * the truth, on the screen someone opens to find out what happened.
 */
class LogOperationException extends ServerOperationException
{
    /**
     * The read was refused rather than broken.
     *
     * A privileged source is tailed through sudo, and a server whose sudoers
     * grant predates the binary that needs is told "no" — which is a known
     * state with a known remedy (re-run install.sh), not a fault needing a
     * support reference. Reported as 403, the same answer the unprivileged
     * branch already gives for a file it cannot open, so the screen can say
     * *this one source* is unavailable instead of failing whole.
     */
    private bool $refused = false;

    public static function refused(): self
    {
        $exception = new self('');
        $exception->refused = true;

        return $exception;
    }

    protected function messageKey(): string
    {
        return $this->refused ? 'errors/log.unreadable' : 'errors/log.read_failed';
    }

    protected function code(): string
    {
        return $this->refused ? 'log_not_permitted' : 'server_operation_failed';
    }

    public function render(Request $request): JsonResponse
    {
        if (! $this->refused) {
            return parent::render($request);
        }

        // No reference: there is nothing in the server-ops log to look up that
        // the message does not already say.
        return response()->json([
            'message' => __($this->messageKey()),
            'code' => $this->code(),
        ], 403);
    }
}
