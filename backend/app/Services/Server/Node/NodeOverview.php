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
 * Mirrors PhpOverview deliberately: same field names, same meanings, so the
 * frontend renders one component for both runtimes and the differences are in
 * the data rather than in the contract.
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
                    // Which npm this version carries — the "Update npm" button
                    // is otherwise a leap of faith.
                    'npm_version' => $npm = $this->node->npmVersion($version['version']),
                    // And what it could carry. Per row, not per registry: npm
                    // 12 cannot run on Node 20, so publishing the registry's
                    // `latest` everywhere would leave the button lit forever
                    // on every version that can never reach it.
                    'npm_latest' => $this->npm->latestFor($version['version']),
                    // The question the button actually asks. Answered here
                    // because it is a semver comparison, and a client doing
                    // it as strings reads '9.8.1' as newer than '10.2.4'.
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
            // So the frontend knows the difference between "this version has
            // no lifecycle data" and "we have no lifecycle data at all" — the
            // second is a box with no egress, and the badges should stay off
            // rather than implying every version is unknown-and-therefore-odd.
            'lifecycle_available' => ! $this->lifecycle->isStale(),
        ];
    }
}
