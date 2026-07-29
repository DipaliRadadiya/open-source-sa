<?php

namespace App\Exceptions\Server\Fail2ban;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Fail2banException extends Exception
{
    private function __construct(
        private readonly string $messageKey,
        private readonly int $status,
        public readonly ?string $reference = null,
        /** @var array<string, mixed> */
        private readonly array $replace = [],
    ) {
        parent::__construct();
    }

    /**
     * fail2ban isn't on the server yet. Not an error so much as a state the
     * screen should offer to fix, so it says so plainly.
     */
    public static function notInstalled(): self
    {
        return new self('errors/fail2ban.not_installed', 422);
    }

    public static function notRunning(): self
    {
        return new self('errors/fail2ban.not_running', 422);
    }

    public static function jailNotActive(string $jail): self
    {
        return new self('errors/fail2ban.jail_not_active', 422, replace: ['jail' => $jail]);
    }

    /**
     * Unbanning an address that isn't banned. Reported rather than silently
     * accepted: the user is looking at a list they believe is current, and
     * agreeing with them when it isn't is how stale UIs persist.
     */
    public static function notBanned(): self
    {
        return new self('errors/fail2ban.not_banned', 404);
    }

    /**
     * Enabling the SSH jail without confirming the ignore list. The one thing
     * that can lock the operator out of their own server.
     */
    public static function lockoutRisk(): self
    {
        return new self('errors/fail2ban.lockout_risk', 422);
    }

    public static function operationFailed(string $reference): self
    {
        return new self('errors/fail2ban.operation_failed', 500, $reference);
    }

    public function render(Request $request): JsonResponse
    {
        $payload = ['message' => __($this->messageKey, $this->replace)];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return response()->json($payload, $this->status);
    }
}
