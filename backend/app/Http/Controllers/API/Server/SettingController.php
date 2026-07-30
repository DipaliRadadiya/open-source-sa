<?php

namespace App\Http\Controllers\API\Server;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Runtime\InstallNodeVersionRequest;
use App\Http\Requests\Server\Runtime\NodeDefaultRequest;
use App\Http\Requests\Server\Runtime\PhpDefaultRequest;
use App\Http\Requests\Server\Runtime\PhpVersionRequest;
use App\Http\Requests\Server\Setting\GeneralSettingsRequest;
use App\Http\Requests\Server\Setting\RebootServerRequest;
use App\Http\Requests\Server\Setting\RedisSettingsRequest;
use App\Http\Requests\Server\Setting\SecuritySettingsRequest;
use App\Http\Requests\Server\Setting\SwapSettingsRequest;
use App\Http\Requests\Server\Setting\UpdateSettingsRequest;
use App\Jobs\InstallNodeVersion;
use App\Jobs\InstallPhpVersion;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Runtimes\NodeRuntime;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\Settings\SettingsManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * All available setting groups with their current values.
     */
    public function index(SettingsManager $settings): JsonResponse
    {
        return response()->json(['settings' => $settings->all()]);
    }

    public function updateGeneral(GeneralSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('general', $request, $settings, $log);
    }

    public function updateSecurity(SecuritySettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('security', $request, $settings, $log);
    }

    public function updateUpdates(UpdateSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('updates', $request, $settings, $log);
    }

    public function updateSwap(SwapSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('swap', $request, $settings, $log);
    }

    public function updateRedis(RedisSettingsRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        return $this->save('redis', $request, $settings, $log);
    }

    /**
     * Choose the version bare `node` resolves to.
     *
     * Only the symlinks move. A site that pinned a version keeps the absolute
     * path already in its unit — changing the server default must not migrate
     * a running site onto a different Node behind its back.
     */
    public function updateNode(NodeDefaultRequest $request, SettingsManager $settings, ActivityLogger $log, NodeRuntime $node): JsonResponse
    {
        $version = (string) $request->validated('default');

        abort_unless($node->installed($version), 422, __('errors/runtime.not_installed', ['runtime' => 'Node', 'version' => $version]));

        $group = $settings->find('node');
        $group->apply($request->validated());

        // Its own verb rather than a generic "settings updated": this
        // re-points system-wide symlinks, which is not what saving a form
        // usually means.
        $log->log('runtime.node_default_changed', null, ['version' => $version]);

        return response()->json(['node' => $group->read()]);
    }

    /**
     * Install a Node version (manage). Queued: unpacking a runtime is far too
     * slow to hold a request open for. `202 Accepted`.
     */
    public function installNodeVersion(InstallNodeVersionRequest $request, NodeRuntime $node, ActivityLogger $log): JsonResponse
    {
        $version = (string) $request->validated('version');

        // Idempotent: asking for a version that is already here is a no-op
        // rather than an error, since the outcome the caller wanted is true.
        if ($node->installed($version)) {
            return response()->json(['message' => __('runtime.already_installed', ['version' => $version])], 200);
        }

        InstallNodeVersion::dispatch($version);
        $log->log('runtime.node_install_started', null, ['version' => $version]);

        return response()->json(['message' => __('runtime.install_started', ['version' => $version])], 202);
    }

    /**
     * Remove a Node version (manage).
     *
     * Refused while a site depends on it, or while it is the default: the
     * failure would otherwise be a site that stops booting with no obvious
     * cause.
     */
    public function destroyNodeVersion(string $version, NodeRuntime $node, ActivityLogger $log): JsonResponse
    {
        abort_unless($node->installed($version), 404);

        $pinned = Application::query()->where('node_version', $version)->pluck('name');

        if ($pinned->isNotEmpty()) {
            return response()->json([
                'message' => __('errors/runtime.version_in_use', ['runtime' => 'Node', 'version' => $version, 'apps' => $pinned->join(', ')]),
            ], 422);
        }

        if ($node->default() === $version) {
            return response()->json(['message' => __('errors/runtime.version_is_default')], 422);
        }

        $node->uninstall($version);
        $log->log('runtime.node_uninstalled', null, ['version' => $version]);

        return response()->json(null, 204);
    }

    /**
     * Update npm inside one version (manage), using that version's own npm.
     */
    public function updateNodeNpm(string $version, NodeRuntime $node, ActivityLogger $log): JsonResponse
    {
        abort_unless($node->installed($version), 404);

        $node->updateNpm($version);
        $log->log('runtime.npm_updated', null, ['version' => $version]);

        return response()->json(['message' => __('runtime.npm_updated', ['version' => $version])]);
    }

    /**
     * Choose the version bare `php` resolves to, via update-alternatives.
     *
     * Only the CLI default moves. A site keeps whatever version its FPM pool
     * runs — this must not migrate a running site.
     */
    public function updatePhp(PhpDefaultRequest $request, SettingsManager $settings, ActivityLogger $log, PhpRuntime $php): JsonResponse
    {
        $version = (string) $request->validated('default');

        abort_unless($php->installed($version), 422, __('errors/runtime.not_installed', ['runtime' => 'PHP', 'version' => $version]));

        $group = $settings->find('php');
        $group->apply($request->validated());

        $log->log('runtime.php_default_changed', null, ['version' => $version]);

        return response()->json(['php' => $group->read()]);
    }

    /**
     * Install a PHP version (manage). Queued — apt takes minutes.
     */
    public function installPhpVersion(PhpVersionRequest $request, PhpRuntime $php, ActivityLogger $log): JsonResponse
    {
        $version = (string) $request->validated('version');

        if ($php->installed($version)) {
            return response()->json(['message' => __('runtime.already_installed', ['version' => $version])], 200);
        }

        InstallPhpVersion::dispatch($version);
        $log->log('runtime.php_install_started', null, ['version' => $version]);

        return response()->json(['message' => __('runtime.install_started', ['version' => $version])], 202);
    }

    /**
     * Remove a PHP version (manage).
     *
     * Three refusals, the last of which is the important one: the panel runs
     * on PHP itself, and removing the version underneath it would take the
     * panel offline from inside the panel — with no way back in to undo it.
     */
    public function destroyPhpVersion(string $version, PhpRuntime $php, ActivityLogger $log): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        if ($version === $php->panelVersion()) {
            return response()->json(['message' => __('errors/runtime.version_runs_panel', ['version' => $version])], 422);
        }

        $pinned = Application::query()->where('php_version', $version)->pluck('name');

        if ($pinned->isNotEmpty()) {
            return response()->json([
                'message' => __('errors/runtime.version_in_use', ['runtime' => 'PHP', 'version' => $version, 'apps' => $pinned->join(', ')]),
            ], 422);
        }

        if ($php->default() === $version) {
            return response()->json(['message' => __('errors/runtime.version_is_default')], 422);
        }

        $php->uninstall($version);
        $log->log('runtime.php_uninstalled', null, ['version' => $version]);

        return response()->json(null, 204);
    }

    /**
     * Schedule a server reboot (guarded — `setting` manage). `202 Accepted`.
     */
    public function reboot(RebootServerRequest $request, ServerOps $ops, ActivityLogger $log): JsonResponse
    {
        $delay = (int) ($request->validated()['delay_minutes'] ?? 0);
        $when = $delay > 0 ? "+{$delay}" : 'now';

        $result = $ops->run(['shutdown', '-r', $when], ['feature' => 'setting', 'group' => 'reboot', 'op' => 'reboot']);

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }

        $log->log('setting.reboot_requested', null, ['when' => $when]);

        return response()->json(['reboot' => ['scheduled' => true, 'when' => $when]], 202);
    }

    private function save(string $key, FormRequest $request, SettingsManager $settings, ActivityLogger $log): JsonResponse
    {
        $group = $settings->find($key);

        if (! $group instanceof SettingGroup || ! $group->available()) {
            abort(404, __('errors/setting.group_unavailable'));
        }

        $group->apply($request->validated());

        $log->log('setting.updated', null, ['group' => $key]);

        return response()->json([$key => $group->read()]);
    }
}
