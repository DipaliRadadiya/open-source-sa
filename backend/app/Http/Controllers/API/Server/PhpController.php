<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Php\PhpDefaultRequest;
use App\Http\Requests\Server\Php\PhpExtensionRequest;
use App\Http\Requests\Server\Php\PhpVersionRequest;
use App\Http\Requests\Server\Php\UpdatePhpIniRequest;
use App\Jobs\InstallPhpExtension;
use App\Jobs\InstallPhpVersion;
use App\Jobs\RemovePhpVersion;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\PhpExtensionManager;
use App\Services\Server\Php\PhpOverview;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Runtimes\PhpRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * PHP as one feature: versions, the default, extensions, and each version's
 * ini — behind one permission.
 *
 * These were previously split between the Services screen (`service`) and the
 * Settings screen (`setting`), which meant managing PHP required both, and
 * `setting` also grants the SSH port and the reboot button. Granting "can
 * change the PHP version" meant granting "can reboot the server".
 *
 * Starting and stopping the FPM daemon deliberately stays on Services: that
 * is the same job there as for nginx or redis, and it is not the same thing
 * as managing PHP.
 */
class PhpController extends Controller
{
    /**
     * Installed versions with the facts a user decides on, plus what apt
     * could still install.
     */
    public function index(PhpOverview $overview): JsonResponse
    {
        return response()->json(['php' => $overview->read()]);
    }

    /**
     * Install a version. Queued — apt takes minutes and holds a lock.
     */
    public function store(PhpVersionRequest $request, PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        $version = (string) $request->validated('version');

        // Idempotent: asking for a version that is already here is a no-op
        // rather than an error, since the outcome the caller wanted is true.
        if ($php->installed($version)) {
            return response()->json(['message' => __('php.already_installed', ['version' => $version])], 200);
        }

        // Before dispatch, not inside the job: a client that polls straight
        // after this 202 must see the version, and the worker may not have
        // picked the job up yet.
        $installs->start('php', $version);

        InstallPhpVersion::dispatch($version, Auth::id());
        $log->log('php.install_started', null, ['version' => $version]);

        return response()->json(['message' => __('php.install_started', ['version' => $version])], 202);
    }

    /**
     * Remove a version.
     *
     * Three refusals, the last of which is the important one: the panel runs
     * on PHP itself, and removing the version underneath it would take the
     * panel offline from inside the panel — with no way back in to undo it.
     */
    public function destroy(string $version, PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        if ($version === $php->panelVersion()) {
            return response()->json(['message' => __('errors/php.version_runs_panel', ['version' => $version])], 422);
        }

        $pinned = Application::query()->where('php_version', $version)->pluck('name');

        if ($pinned->isNotEmpty()) {
            return response()->json([
                'message' => __('errors/php.version_in_use', ['version' => $version, 'apps' => $pinned->join(', ')]),
            ], 422);
        }

        if ($php->default() === $version) {
            return response()->json(['message' => __('errors/php.version_is_default')], 422);
        }

        // Queued, and recorded before dispatch for the same reason installing
        // is: a client that reloads straight after this must see the version
        // marked `removing` rather than watch it sit there looking untouched.
        //
        // It used to purge inside this request. apt takes minutes and nginx
        // ends a request at fastcgi_read_timeout, so the browser got a
        // timeout while the purge carried on — the screen never refreshed,
        // the version vanished on its own a minute later, and pressing Remove
        // again answered 404 for something already gone.
        $installs->startRemoval('php', $version);
        RemovePhpVersion::dispatch($version, Auth::id());

        return response()->json(
            ['message' => __('php.uninstall_started', ['version' => $version])],
            202,
        );
    }

    /**
     * Choose the version bare `php` resolves to, via update-alternatives.
     *
     * Only the CLI default moves. A site keeps whatever version its FPM pool
     * runs — this must not migrate a running site.
     */
    public function setDefault(PhpDefaultRequest $request, PhpRuntime $php, PhpOverview $overview, ActivityLogger $log): JsonResponse
    {
        $version = (string) $request->validated('default');

        abort_unless($php->installed($version), 422, __('errors/php.not_installed', ['version' => $version]));

        $php->setDefault($version);
        $log->log('php.default_changed', null, ['version' => $version]);

        return response()->json(['php' => $overview->read()]);
    }

    /**
     * The raw php.ini for a version, for the editor to load.
     */
    public function showIni(string $version, PhpVersionManager $php): JsonResponse
    {
        return response()->json(['php_ini' => $this->ini($version, $php)]);
    }

    /**
     * Replace the ini. Backed up, validated, and rolled back if PHP rejects
     * it — a broken ini can stop FPM from starting at all, which would take
     * every site on that version down.
     */
    public function updateIni(string $version, UpdatePhpIniRequest $request, PhpVersionManager $php, ActivityLogger $log): JsonResponse
    {
        $php->writeIni($version, $request->string('contents')->toString());

        $log->log('php.ini_updated', properties: ['version' => $version]);

        return response()->json(['php_ini' => $this->ini($version, $php)]);
    }

    /**
     * Every extension available to a version, with its current state.
     */
    public function extensions(string $version, PhpExtensionManager $extensions, PhpRuntime $php): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        return response()->json([
            'extensions' => $extensions->catalog($version),
            'panel_required' => $version === $php->panelVersion() ? $extensions->panelRequired() : [],
        ]);
    }

    /**
     * Turn one extension on or off for a version.
     *
     * One toggle, not two controls. On installs the package if it is missing;
     * off only unlinks it. Nothing is ever purged — a disabled extension
     * costs a few megabytes, and purging is how `php8.4-common` goes with it
     * and takes every site on the server down.
     */
    public function updateExtension(
        PhpExtensionRequest $request,
        string $version,
        string $extension,
        PhpExtensionManager $extensions,
        PhpRuntime $php,
        ActivityLogger $log,
        InstallTracker $installs,
    ): JsonResponse {
        abort_unless($php->installed($version), 404);

        // The name becomes an apt package and a path. A pattern is not enough
        // — it has to be something this server actually offers.
        $row = $extensions->find($version, $extension);

        abort_if($row === null, 404);

        if ($row['builtin']) {
            return response()->json(['message' => __('errors/php.extension_builtin', ['extension' => $extension])], 422);
        }

        $enabled = (bool) $request->validated('enabled');
        $properties = ['version' => $version, 'extension' => $extension];

        if (! $enabled) {
            // Only on the panel's own version. Another version may disable
            // whatever it likes — the panel is not running on it.
            if ($version === $php->panelVersion() && ($blockers = $extensions->panelBlockers($row['modules'])) !== []) {
                return response()->json([
                    'message' => __('errors/php.extension_runs_panel', [
                        'extension' => $extension,
                        'modules' => implode(', ', $blockers),
                    ]),
                ], 422);
            }

            $extensions->disable($version, $extension);
            $log->log('php.extension_disabled', null, $properties);

            return response()->json(['extension' => $extensions->find($version, $extension)]);
        }

        if (! $row['installed']) {
            // Same ordering as a version install: recorded before dispatch so
            // the row is already `installing` when the client re-reads.
            $installs->start('php', $version, $extension);

            InstallPhpExtension::dispatch($version, $extension, Auth::id());
            $log->log('php.extension_install_started', null, $properties);

            return response()->json([
                'message' => __('php.extension_install_started', ['extension' => $extension]),
            ], 202);
        }

        $extensions->enable($version, $extension);
        $log->log('php.extension_enabled', null, $properties);

        return response()->json(['extension' => $extensions->find($version, $extension)]);
    }

    /**
     * @return array{version: string, path: string, contents: string}
     */
    private function ini(string $version, PhpVersionManager $php): array
    {
        return [
            'version' => $version,
            'path' => $php->iniPath($version),
            'contents' => $php->readIni($version),
        ];
    }
}
