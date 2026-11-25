<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\InstallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Node\InstallNodeVersionRequest;
use App\Http\Requests\Server\Node\NodeDefaultRequest;
use App\Jobs\InstallNodeVersion;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Runtime\NpmCatalog;
use App\Services\Runtime\PinnedSites;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Node\NodeOverview;
use App\Services\Server\Runtimes\NodeRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Node as its own feature, mirroring PHP, with its own permission (not the broader `setting`).
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
     * Only the symlinks move: a site that pinned a version keeps the absolute path in its unit,
     * so changing the default never migrates a running site onto a different Node.
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
     * Install a version. Queued: unpacking a runtime is too slow for a request.
     */
    public function store(InstallNodeVersionRequest $request, NodeRuntime $node, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        $version = (string) $request->validated('version');

        // Idempotent: an already-installed version is a no-op, not an error.
        if ($node->installed($version)) {
            return response()->json(['message' => __('node.already_installed', ['version' => $version])], 200);
        }

        // A second click or tab must not start a second install of the same version.
        if (in_array($installs->current('node', $version)?->status, [InstallStatus::Installing, InstallStatus::Removing], true)) {
            return response()->json([
                'message' => __('errors/node.install_in_progress', ['version' => $version]),
                'reason' => 'install_in_progress',
            ], 409);
        }

        // Before dispatch: a client polling right after this 202 must already see the version.
        $installs->start('node', $version);

        InstallNodeVersion::dispatch($version, Auth::id());
        $log->log('node.install_started', null, ['version' => $version]);

        return response()->json(['message' => __('node.install_started', ['version' => $version])], 202);
    }

    /**
     * Remove a version.
     *
     * Refused while a site depends on it or while it is the default, or a site would stop booting.
     * The message names every site (the list response caps them).
     */
    public function destroy(string $version, NodeRuntime $node, PinnedSites $pinned, ActivityLogger $log, ServerCapabilities $capabilities): JsonResponse
    {
        if (! $node->installed($version)) {
            // A failed install leaves an entry with nothing installed; Remove clears it.
            $failed = app(InstallTracker::class)->current('node', $version);
            abort_unless($failed?->status === InstallStatus::Failed, 404);

            $failed->delete();

            return response()->json(null, 204);
        }

        $sites = $pinned->allFor('node_version', $version);

        if ($sites !== []) {
            return response()->json([
                'message' => __('errors/node.version_in_use', ['version' => $version, 'apps' => implode(', ', $sites)]),
            ], 422);
        }

        if ($node->default() === $version) {
            return response()->json(['message' => __('errors/node.version_is_default')], 422);
        }

        if ($node->panelVersion() === $version) {
            return response()->json(['message' => __('errors/node.version_runs_panel', ['version' => $version])], 422);
        }

        $node->uninstall($version);

        // Mirror of the refresh after an install: the recorded capability must stop claiming Node.
        $capabilities->refresh();

        $log->log('node.uninstalled', null, ['version' => $version]);

        return response()->json(null, 204);
    }

    /**
     * Update npm inside one version, using that version's own npm.
     */
    public function updateNpm(string $version, NodeRuntime $node, NpmCatalog $npm, ActivityLogger $log): JsonResponse
    {
        abort_unless($node->installed($version), 404);

        // Refuse rather than guess: `npm@latest` can be an npm this Node version cannot run.
        $target = $npm->resolveTarget($version);

        if ($target === null) {
            return response()->json(['message' => __('errors/node.npm_target_unknown')], 422);
        }

        $node->updateNpm($version, $target);
        $log->log('node.npm_updated', null, ['version' => $version]);

        $installed = $node->npmVersion($version);

        return response()->json([
            'message' => __('node.npm_updated', ['version' => $version]),
            'npm_version' => $installed,
            // The same three fields the list sends, so the row updates without a refetch.
            'npm_latest' => $npm->latestFor($version),
            'npm_update_available' => $npm->updateAvailable($version, $installed),
        ]);
    }
}
