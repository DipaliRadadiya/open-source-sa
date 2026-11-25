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
     * fail2ban isn't on the server yet: a state the screen should offer to fix.
     */
    public static function notInstalled(): self
    {
        return new self('errors/fail2ban.not_installed', 422);
    }

    /**
     * `jail.local` exists and the panel did not write it.
     *
     * Refused rather than overwritten: a migrated server or a hand-configured administrator owns
     * that file, and the panel replaces it wholesale. 409, not 422: nothing the user typed is
     * wrong; the server state conflicts with the request.
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
     * Unbanning an address that isn't banned. Reported rather than silently accepted: the
     * user's list is out of date.
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

    /** Adding an entry the ignore list already has. */
    public static function ignoreIpExists(): self
    {
        return new self('errors/fail2ban.ignore_ip_exists', 422);
    }

    /**
     * Removing an entry the list does not have: 404, like {@see self::notBanned()}.
     */
    public static function ignoreIpMissing(): self
    {
        return new self('errors/fail2ban.ignore_ip_missing', 404);
    }

    public static function ignoreListFull(): self
    {
        return new self('errors/fail2ban.ignore_list_full', 422);
    }

    public static function operationFailed(string $reference): self
    {
        return new self('errors/fail2ban.operation_failed', 500, $reference);
    }

    public function render(Request $request): JsonResponse
    {
        $payload = [
            'message' => __($this->messageKey, $this->replace),
            // A stable code beside the sentence: the ignore-list route alone answers four
            // different 422s.
            'reason' => substr($this->messageKey, strrpos($this->messageKey, '.') + 1),
        ];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return response()->json($payload, $this->status);
    }
}
