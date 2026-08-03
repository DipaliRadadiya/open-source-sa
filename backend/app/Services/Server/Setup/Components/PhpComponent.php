<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * PHP. Always installed — the installer put it there, because the panel's own API
 * is PHP and could not be answering this request otherwise.
 *
 * It is on the list anyway so the page shows the whole picture rather than only
 * the gaps, and because the row is where someone adds a *second* version for a
 * site that needs one.
 */
class PhpComponent implements SetupComponent
{
    public function __construct(private PhpRuntime $php) {}

    public function key(): string
    {
        return 'php';
    }

    public function installed(): bool
    {
        return $this->php->versions() !== [];
    }

    public function recommended(): bool
    {
        return false;
    }

    public function detail(): ?string
    {
        // `versions()` returns records, not strings — each carries the binary path
        // and whether it is the default alongside the number.
        $versions = array_map(fn (array $v) => (string) $v['version'], $this->php->versions());

        return $versions === [] ? null : implode(', ', $versions);
    }

    public function action(): ?array
    {
        // Adding a version takes the version as a body field, so this is a link
        // to the feature rather than a one-click install.
        return ['method' => 'POST', 'endpoint' => '/api/php/versions'];
    }

    public function options(): array
    {
        return [];
    }
}
