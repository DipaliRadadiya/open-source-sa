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
     * The PID is not running. A 404, not a quiet success: PIDs are recycled, so "killed" would be
     * indistinguishable from having killed whatever holds that number now.
     */
    public static function notFound(): self
    {
        return new self('errors/process.not_found', 404);
    }

    /**
     * PID 1, or a process of a service the panel protects (a PID is no way around that).
     */
    public static function protectedProcess(): self
    {
        return new self('errors/process.protected', 422);
    }

    /**
     * A database server: killing it takes every site's database offline.
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
     * The panel's own process: killing it would end the request doing the killing.
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
     * TERM was delivered and the process is still there. A 409 so the screen offers Force stop.
     */
    public static function stillRunning(): self
    {
        return new self('errors/process.still_running', 409);
    }

    /**
     * KILL cannot be ignored, so a survivor is stuck in the kernel (usually a disk or network
     * mount). There is no stronger signal.
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
     * The user-facing sentence, for a caller that reports the refusal instead of throwing it.
     */
    public function reason(): string
    {
        return __($this->messageKey);
    }

    /**
     * A fixed code for callers that cannot read a translated sentence, from the message key:
     * `errors/process.not_found` → `process_not_found`.
     */
    public function code(): string
    {
        return 'process_'.substr($this->messageKey, strlen('errors/process.'));
    }

    public function render(Request $request): JsonResponse
    {
        $payload = ['message' => $this->reason(), 'code' => $this->code()];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return response()->json($payload, $this->status);
    }
}
