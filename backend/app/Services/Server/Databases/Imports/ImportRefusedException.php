<?php

namespace App\Services\Server\Databases\Imports;

use RuntimeException;

/**
 * An uploaded file the import will not load. `reason` is the stored code
 * (`database.import_failed.{reason}`); `lineNumber` points the user at the place,
 * when there is one.
 */
class ImportRefusedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        // Not `$line`: Exception already has one (where it was thrown), and
        // redeclaring it is a fatal error the moment this class loads.
        public readonly ?int $lineNumber = null,
    ) {
        parent::__construct($reason.($lineNumber !== null ? " (line {$lineNumber})" : ''));
    }
}
