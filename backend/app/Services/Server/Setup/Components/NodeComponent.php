<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Runtimes\NodeRuntime;

/**
 * Node, managed with fnm.
 *
 * Detection asks fnm, not `which node`: a system Node from apt is reported by the
 * panel as an untouchable system binary it cannot switch versions on. A server
 * where only that exists is honestly "not managed", because the version picker
 * would be inert.
 */
class NodeComponent implements SetupComponent
{
    public function __construct(private NodeRuntime $node) {}

    public function key(): string
    {
        return 'node';
    }

    public function installed(): bool
    {
        return $this->node->fnmInstalled() && $this->node->versions() !== [];
    }

    public function recommended(): bool
    {
        return false;
    }

    public function detail(): ?string
    {
        if (! $this->node->fnmInstalled()) {
            return null;
        }

        $versions = array_map(fn (array $v) => (string) $v['version'], $this->node->versions());

        return $versions === [] ? null : implode(', ', $versions);
    }

    public function action(): ?array
    {
        return ['method' => 'POST', 'endpoint' => '/api/node/versions'];
    }

    public function options(): array
    {
        return [];
    }
}
