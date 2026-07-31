<?php

namespace App\Exceptions\Server\Database;

use Exception;

/**
 * A database engine could not be installed, or could be installed but not
 * credentialed.
 *
 * Carries a classified `reason` code rather than stderr, for the same reason the
 * runtime installers do: apt's wording changes between releases, it is not
 * translatable, and it leaks paths. The human sentence is built from the code at
 * read time in the viewer's locale; the raw output stays in the server-ops log
 * under `reference`.
 */
class EngineInstallException extends Exception
{
    private function __construct(
        public readonly string $reason,
        public readonly ?string $reference = null,
    ) {
        parent::__construct("Database engine install failed [{$reason}]");
    }

    public static function because(string $reason, ?string $reference = null): self
    {
        return new self($reason, $reference);
    }

    /**
     * Another engine already owns the port. Refused rather than installed
     * alongside: two engines fighting over 3306 leaves the second one dead and
     * the panel pointing at whichever won.
     */
    public static function portTaken(string $by): self
    {
        return new self('port_in_use_by_'.$by);
    }

    /**
     * We could not log in as root over the socket, which means someone has
     * already changed how root authenticates. Refused rather than guessed —
     * users migrate real servers into this panel, and overwriting an existing
     * root credential could lock out whatever else on the box uses it.
     */
    public static function rootUnreachable(?string $reference = null): self
    {
        return new self('root_unreachable', $reference);
    }
}
