<?php

namespace App\Exceptions\Server\Application;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A file request refused for a reason the user can act on.
 *
 * Shaped like a validation error — `errors.{field}` — plus a `reason` code
 * (APP-3/APP-4). A bare `abort(422, …)` carried only a sentence, so the
 * screen could show it as a pop-up and nothing else: it could not put it
 * under the field that caused it, nor tell "already exists" from "not an
 * archive" without matching translated text.
 */
class FileRefusedException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $field, string $reason, string $key, array $replace = [], int $status = 422): self
    {
        return new self($field, $reason, __($key, $replace), $status);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
            'errors' => [$this->field => [$this->getMessage()]],
        ], $this->status);
    }
}
