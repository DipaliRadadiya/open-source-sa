<?php

namespace App\Exceptions\Server;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for server-operation failures. Renders a translated, user-friendly
 * message (never raw stderr) plus a reference id the user can quote to
 * support — the raw technical detail lives only in the server-ops log,
 * correlated by that reference.
 */
abstract class ServerOperationException extends Exception
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct();
    }

    /**
     * The `lang` key for the friendly message.
     */
    abstract protected function messageKey(): string;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __($this->messageKey()),
            'reference' => $this->reference,
        ], 500);
    }
}
