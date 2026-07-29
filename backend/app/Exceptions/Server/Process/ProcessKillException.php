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

    public static function failed(string $reference): self
    {
        return new self('errors/process.kill_failed', 500, $reference);
    }

    public function render(Request $request): JsonResponse
    {
        $payload = ['message' => __($this->messageKey)];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return response()->json($payload, $this->status);
    }
}
