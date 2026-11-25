<?php

namespace App\Services\Server\Node;

use App\Services\Runtime\LifecycleCatalog;
use App\Services\Runtime\NpmCatalog;
use App\Services\Runtime\PinnedSites;
use App\Services\Runtime\RuntimeProgress;
use App\Services\Server\Runtimes\NodeRuntime;

/**
 * What the Node screen shows.
 *
 * Mirrors PhpOverview (same field names and meanings) so the frontend renders one
 * component for both runtimes.
 */
class NodeOverview
{
    public function __construct(
        private NodeRuntime $node,
        private PinnedSites $pinned,
        private LifecycleCatalog $lifecycle,
        private NpmCatalog $npm,
        private RuntimeProgress $progress,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $sites = $this->pinned->summary('node_version');

        $progress = $this->progress->apply(
            'node',
            array_map(function (array $version) use ($sites) {
                $pinned = $sites[$version['version']] ?? null;

                return [
                    ...$version,
                    'npm_version' => $npm = $this->node->npmVersion($version['version']),
                    // Per row, not per registry: npm 12 cannot run on Node 20, so one
                    // global `latest` would leave "Update npm" lit forever there.
                    'npm_latest' => $this->npm->latestFor($version['version']),
                    // Semver comparison done here: clients comparing strings read '9.8.1' > '10.2.4'.
                    'npm_update_available' => $this->npm->updateAvailable($version['version'], $npm),
                    'in_use_by' => $pinned['count'] ?? 0,
                    'sites' => $pinned['names'] ?? [],
                    'sites_truncated' => $pinned['truncated'] ?? false,
                    'lifecycle' => $this->lifecycle->for('node', $version['version']),
                ];
            }, $this->node->versions()),
            array_map(fn (string $version) => [
                'version' => $version,
                'lifecycle' => $this->lifecycle->for('node', $version),
            ], $this->node->installable()),
        );

        return [
            'manager' => $this->node->manager(),
            'default' => $this->node->default(),
            'versions' => $progress['versions'],
            'system' => $this->node->system(),
            'installable' => $progress['installable'],
            // `unreachable` when the version list could not be fetched: show "could not
            // reach nodejs.org", not "nothing to install".
            'installable_error' => $this->node->installableError(),
            // Tells "this version has no lifecycle data" from "no lifecycle data at all"
            // (no egress); in the second case the badges stay off.
            'lifecycle_available' => ! $this->lifecycle->isStale(),
        ];
    }
}
