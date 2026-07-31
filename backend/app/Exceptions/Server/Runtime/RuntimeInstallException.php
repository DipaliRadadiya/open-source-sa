<?php

namespace App\Exceptions\Server\Runtime;

use App\Exceptions\Server\ServerOperationException;

/**
 * An install failed, carrying *why* in a form that can be shown to a user.
 *
 * It carries a classified reason code, never the command output. The output
 * stays in the server-ops log under `reference`: it names internal paths, it
 * cannot be translated, and it is exactly the sort of thing that leaks into
 * an API response once it is available to one.
 */
class RuntimeInstallException extends ServerOperationException
{
    public function __construct(
        string $reference,
        public readonly string $reason = 'unknown',
    ) {
        parent::__construct($reference);
    }

    protected function messageKey(): string
    {
        return 'errors/runtime.install_failed';
    }
}
