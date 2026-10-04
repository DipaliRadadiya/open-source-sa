<?php

namespace App\Exceptions\Server\Process;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessKillException extends Exception
{
    private function __construct(
        private readonly string $messageKey,
        private readonly int $status,
        public readonly ?string $reference = null,
    ) {
        parent::__construct();
    }

    /**
     * The PID is not running. A 404 rather than a quiet success, because PIDs
     * are recycled: reporting "killed" for a PID that has already exited would
     * be indistinguishable from having killed whatever now holds that number.
     */
    public static function notFound(): self
    {
        return new self('errors/process.not_found', 404);
    }

    /**
     * PID 1, or a process belonging to a service the panel protects. Refused
     * for the same reason those services can't be stopped from the Services
     * screen — a PID is not a way around that.
     */
    public static function protectedProcess(): self
    {
        return new self('errors/process.protected', 422);
    }

    /**
     * A database server. Stopping it takes every site's database offline;
     * the Services screen restarts it instead.
     */
    public static function databaseEngine(): self
    {
        return new self('errors/process.database', 422);
    }

    public static function kernelThread(): self
    {
        return new self('errors/process.kernel_thread', 422);
    }

    /**
     * The panel's own process. Killing it would end the request doing the
     * killing, and take away the way back in.
     */
    public static function self(): self
    {
        return new self('errors/process.self', 422);
    }

    /**
     * A refusal from ProcessKiller::refusal(), by its reason.
     */
    public static function refused(string $reason): self
    {
        return match ($reason) {
            'database' => self::databaseEngine(),
            'kernel_thread' => self::kernelThread(),
            'self' => self::self(),
            'gone' => self::notFound(),
            default => self::protectedProcess(),
        };
    }

    /**
     * TERM was delivered and the process is still there (bug #6). A 409 so
     * the screen's existing error path offers Force stop — the process is in
     * a state the request did not change, not one it may not touch.
     */
    public static function stillRunning(): self
    {
        return new self('errors/process.still_running', 409);
    }

    /**
     * KILL cannot be ignored, so a process that survives it is stuck inside
     * the kernel (usually waiting on a disk or network mount). There is no
     * stronger signal to offer.
     */
    public static function survivedKill(): self
    {
        return new self('errors/process.still_running_after_kill', 409);
    }

    public static function failed(string $reference): self
    {
        return new self('errors/process.kill_failed', 500, $reference);
    }

    /**
     * The user-facing sentence, for a caller that reports the refusal
     * rather than throwing it — the process list's `reason`.
     */
    public function reason(): string
    {
        return __($this->messageKey);
    }

    public function render(Request $request): JsonResponse
    {
        $payload = ['message' => $this->reason()];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return response()->json($payload, $this->status);
    }
}
