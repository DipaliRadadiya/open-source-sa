<?php

namespace App\Exceptions\Server\Database;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Root refused the panel, and only root's current password would get it in
 * (RC-26, 2026-11-05).
 *
 * Shaped like a validation error under `current_password`, plus a `reason`
 * (as FileRefusedException): the root-password dialog asks for the current
 * password only on `root_unreachable`, and on `current_password_wrong` keeps
 * the field open with the message under it. Matching translated text would
 * break in every locale but the one it was written in.
 */
class RootAccessRefusedException extends RuntimeException
{
    public const FIELD = 'current_password';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** No way in, and no current password was given: ask for it. */
    public static function needsCurrentPassword(string $engine): self
    {
        return new self('root_unreachable', __('errors/database.root_unreachable', ['engine' => $engine]));
    }

    /** The current password given did not sign root in either. */
    public static function wrongCurrentPassword(): self
    {
        return new self('current_password_wrong', __('errors/database.root_password_wrong'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
            'errors' => [self::FIELD => [$this->getMessage()]],
        ], 422);
    }
}
