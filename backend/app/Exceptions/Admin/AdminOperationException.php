<?php

namespace App\Exceptions\Admin;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for admin-area failures that must render as a translated, user-facing
 * message plus a reference id the caller can quote to support.
 *
 * Mirrors `App\Exceptions\Server\ServerOperationException` so the admin
 * area gets the same shape: the raw detail lives in the log under
 * `reference`, the user sees the friendly message. Subclasses set the lang
 * key and (where needed) the HTTP status — the default is 500 for an
 * unexpected failure, 409 for a state conflict, etc.
 */
abstract class AdminOperationException extends Exception
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct();
    }

    abstract protected function messageKey(): string;

    public function status(): int
    {
        return 500;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __($this->messageKey()),
            'reference' => $this->reference,
        ], $this->status());
    }
}