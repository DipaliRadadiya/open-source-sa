<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Node\InstallNodeVersionRequest;
use App\Http\Requests\Server\Node\NodeDefaultRequest;
use App\Jobs\InstallNodeVersion;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Runtime\PinnedSites;
use App\Services\Server\Node\NodeOverview;
use App\Services\Server\Runtimes\NodeRuntime;
use Illuminate\Http\JsonResponse;

/**
 * Node as its own feature, mirroring PHP.
 *
 * It was a section of the Settings screen, gated by `setting` — which also
 * grants the SSH port and the reboot button. Someone who manages Node apps
 * should not need that, and Node needed a permission of its own before it
 * could have a sidebar row at all.
 */
class NodeController extends Controller
{
    public function index(NodeOverview $overview): JsonResponse
    {
        return response()->json(['node' => $overview->read()]);
    }

    /**
     * Choose the version bare `node` resolves to.
     *
     * Only the symlinks move. A site that pinned a version keeps the absolute
     * path already in its unit — changing the server default must not migrate
     * a running site onto a different Node behind its back.
     */
    public function setDefault(NodeDefaultRequest $request, NodeRuntime $node, NodeOverview $overview, ActivityLogger $log): JsonResponse
    {
        $version = (string) $request->validated('default');

        abort_unless($node->installed($version), 422, __('errors/node.not_installed', ['version' => $version]));

        $node->setDefault($version);
        $log->log('node.default_changed', null, ['version' => $version]);

        return response()->json(['node' => $overview->read()]);
    }

    /**
     * Install a version. Queued: unpacking a runtime is far too slow to hold
     * a request open for.
     */
    public function store(InstallNodeVersionRequest $request, NodeRuntime $node, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        $version = (string) $request->validated('version');

        // Idempotent: asking for a version that is already here is a no-op
        // rather than an error, since the outcome the caller wanted is true.
        if ($node->installed($version)) {
            return response()->json(['message' => __('node.already_installed', ['version' => $version])], 200);
        }

        // Before dispatch: a client polling straight after this 202 must see
        // the version, and the worker may not have started yet.
        $installs->start('node', $version);

        InstallNodeVersion::dispatch($version);
        $log->log('node.install_started', null, ['version' => $version]);

        return response()->json(['message' => __('node.install_started', ['version' => $version])], 202);
    }

    /**
     * Remove a version.
     *
     * Refused while a site depends on it, or while it is the default: the
     * failure would otherwise be a site that stops booting with no obvious
     * cause. The message names every site — unlike the list response, which
     * caps them, this is the one place completeness is the point.
     */
    public function destroy(string $version, NodeRuntime $node, PinnedSites $pinned, ActivityLogger $log): JsonResponse
    {
        abort_unless($node->installed($version), 404);

        $sites = $pinned->allFor('node_version', $version);

        if ($sites !== []) {
            return response()->json([
                'message' => __('errors/node.version_in_use', ['version' => $version, 'apps' => implode(', ', $sites)]),
            ], 422);
        }

        if ($node->default() === $version) {
            return response()->json(['message' => __('errors/node.version_is_default')], 422);
        }

        $node->uninstall($version);
        $log->log('node.uninstalled', null, ['version' => $version]);

        return response()->json(null, 204);
    }

    /**
     * Update npm inside one version, using that version's own npm.
     */
    public function updateNpm(string $version, NodeRuntime $node, ActivityLogger $log): JsonResponse
    {
        abort_unless($node->installed($version), 404);

        $node->updateNpm($version);
        $log->log('node.npm_updated', null, ['version' => $version]);

        return response()->json([
            'message' => __('node.npm_updated', ['version' => $version]),
            'npm_version' => $node->npmVersion($version),
        ]);
    }
}
