<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\BuildTools\BuildToolsManager;

/**
 * The compiler toolchain. Installed by `install.sh` on every new server, so
 * this row exists for the ones that predate that — and for the case where
 * somebody removed it.
 *
 * Recommended rather than required: a server hosting only WordPress and static
 * sites never compiles anything and is perfectly healthy without it. The row
 * earns its place because the failure it prevents is unreadable — npm buries
 * `not found: make` under thousands of peer-dependency warnings, so a missing
 * compiler presents as a dependency problem, and the panel's own message for
 * that failure used to tell the user to "install the build tools" with no way
 * in the product to do it.
 */
class BuildToolsComponent implements SetupComponent
{
    public function __construct(private BuildToolsManager $buildTools) {}

    public function key(): string
    {
        return 'build_tools';
    }

    public function installed(): bool
    {
        return $this->buildTools->installed();
    }

    public function recommended(): bool
    {
        return true;
    }

    /**
     * Names what is missing rather than asserting the whole toolchain is gone.
     * A box with `make` and no compiler is a different problem from a bare one,
     * and the difference is worth a sentence when someone is reading the row.
     */
    public function detail(): ?string
    {
        $missing = $this->buildTools->missing();

        return $missing === [] ? null : implode(', ', $missing);
    }

    public function action(): ?array
    {
        return ['method' => 'POST', 'endpoint' => '/api/build-tools/install'];
    }

    public function options(): array
    {
        return [];
    }
}
