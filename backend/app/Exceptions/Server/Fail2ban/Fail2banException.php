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

    /**
     * `jail.local` exists and the panel did not write it.
     *
     * Refused rather than overwritten: a server migrated from another panel,
     * or an administrator who configured fail2ban by hand, owns that file, and
     * this class replaces it wholesale. 409 rather than 422 — nothing the user
     * typed is wrong; the server is in a state that conflicts with the request,
     * and the fix is a decision about a file rather than a corrected field.
     */
    public static function foreignJailLocal(string $path): self
    {
        return new self('errors/fail2ban.foreign_jail_local', 409, replace: ['path' => $path]);
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
