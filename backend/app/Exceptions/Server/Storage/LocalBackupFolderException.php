<?php

namespace App\Exceptions\Server\Storage;

use RuntimeException;

/**
 * A local backup folder that cannot be used, and why — as a translation key,
 * so the user reads the reason in their own language, not the panel's.
 */
class LocalBackupFolderException extends RuntimeException
{
    /**
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $reference = null,
        public readonly array $parameters = [],
    ) {
        parent::__construct($key);
    }

    public function userMessage(): string
    {
        return __($this->key, $this->parameters);
    }
}
