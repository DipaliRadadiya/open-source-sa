<?php

namespace App\Exceptions\Server\Runtime;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * fnm would not remove a Node version (FS-C21).
 *
 * It answered "The settings change failed on the server." — the wrong
 * subject and no reason, while fnm had said exactly why ("Can't delete
 * Node.js version: Operation not permitted"). The install path already
 * names its cause; this does the same for removal.
 */
class NodeRemovalException extends RuntimeException
{
    public function __construct(
        public readonly string $version,
        public readonly string $reference,
        public readonly ?string $said = null,
    ) {
        parent::__construct("Node {$version} could not be removed (reference {$reference})");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->said === null
                ? __('errors/node.remove_failed', ['version' => $this->version])
                : __('errors/node.remove_failed_said', ['version' => $this->version, 'output' => $this->said]),
            'reason' => 'remove_failed',
            'output' => $this->said,
            'reference' => $this->reference,
        ], 500);
    }
}
