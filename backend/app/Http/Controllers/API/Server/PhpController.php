<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\InstallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Php\IonCubeRequest;
use App\Http\Requests\Server\Php\PhpDefaultRequest;
use App\Http\Requests\Server\Php\PhpExtensionRequest;
use App\Http\Requests\Server\Php\PhpVersionRequest;
use App\Http\Requests\Server\Php\SavePhpIniSettingsRequest;
use App\Http\Requests\Server\Php\UpdatePhpIniRequest;
use App\Jobs\InstallIonCubeLoader;
use App\Jobs\InstallPhpExtension;
use App\Jobs\InstallPhpVersion;
use App\Jobs\RemovePhpVersion;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Cron\CronPhpBinary;
use App\Services\Server\Php\IonCubeLoader;
use App\Services\Server\Php\PhpExtensionManager;
use App\Services\Server\Php\PhpIniDirectives;
use App\Services\Server\Php\PhpOverview;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Runtimes\PhpRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * PHP as one feature: versions, the default, extensions, and each version's ini, behind one
 * permission (not `setting`, which also grants the SSH port and reboot).
 *
 * Starting and stopping the FPM daemon stays on Services, as for nginx or redis.
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

        // Idempotent: an already-installed version is a no-op, but "installed" must mean
        // *usable*. `openlitespeed` pulls in a bare `lsphp83` (no curl, sqlite3, redis, ...),
        // so missing base packages fall through to the install, which apt makes idempotent.
        if ($php->installed($version) && $php->missingBasePackages($version) === []) {
            return response()->json(['message' => __('php.already_installed', ['version' => $version])], 200);
        }

        // Before dispatch: a client polling right after this 202 must already see the version.
        $installs->start('php', $version);

        InstallPhpVersion::dispatch($version, Auth::id());
        $log->log('php.install_started', null, ['version' => $version]);

        return response()->json(['message' => __('php.install_started', ['version' => $version])], 202);
    }

    /**
     * Remove a version.
     *
     * Refuses the panel's own version: removing it would take the panel offline with no way
     * back in.
     */
    public function destroy(string $version, PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        if (! $php->installed($version)) {
            // A failed install leaves an entry with nothing installed behind it; Remove
            // dismisses it (as for Node).
            $failed = $installs->current('php', $version);
            abort_unless($failed?->status === InstallStatus::Failed, 404);

            $failed->delete();

            return response()->json(null, 204);
        }

        if ($version === $php->panelVersion()) {
            return response()->json(['message' => __('errors/php.version_runs_panel', ['version' => $version])], 422);
        }

        $pinned = Application::query()->where('php_version', $version)->pluck('name');

        if ($pinned->isNotEmpty()) {
            return response()->json([
                'message' => __('errors/php.version_in_use', ['version' => $version, 'apps' => $pinned->join(', ')]),
            ], 422);
        }

        // Same rule for cron jobs naming this version (each would fail on every tick).
        // Plain `php` is not counted: it follows the default.
        $crons = app(CronPhpBinary::class)->namingVersion($version);

        if ($crons !== []) {
            return response()->json([
                'message' => __('errors/php.version_used_by_cronjobs', ['version' => $version, 'cronjobs' => implode(', ', $crons)]),
            ], 422);
        }

        if ($php->default() === $version) {
            return response()->json(['message' => __('errors/php.version_is_default')], 422);
        }

        // Queued (apt takes minutes, past nginx's fastcgi_read_timeout), and recorded before
        // dispatch so a reload straight after sees the version as `removing`.
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
     * Only the CLI default moves; a site keeps the version its FPM pool runs.
     */
    public function setDefault(PhpDefaultRequest $request, PhpRuntime $php, PhpOverview $overview, ActivityLogger $log): JsonResponse
    {
        $version = (string) $request->validated('default');

        abort_unless($php->installed($version), 422, __('errors/php.not_installed', ['version' => $version]));

        $php->setDefault($version);
        $log->log('php.default_changed', null, ['version' => $version]);

        return response()->json([
            'php' => $overview->read(),
            // Plain `php` is the default, so these now run on `$version`; lets the user pin them.
            'cronjobs_on_default_php' => app(CronPhpBinary::class)->onServerDefault(),
        ]);
    }

    /**
     * The raw php.ini for a version, for the editor to load.
     */
    public function showIni(string $version, PhpVersionManager $php): JsonResponse
    {
        return response()->json(['php_ini' => $this->ini($version, $php)]);
    }

    /**
     * Replace the ini. Backed up, validated, and rolled back if PHP rejects it: a broken ini can
     * stop FPM and take every site on that version down.
     */
    public function updateIni(string $version, UpdatePhpIniRequest $request, PhpVersionManager $php, ActivityLogger $log): JsonResponse
    {
        $php->writeIni($version, $request->string('contents')->toString(), $request->safetyCopy());

        $log->log('php.ini_updated', properties: ['version' => $version, 'safety_copy' => $request->safetyCopy()]);

        return response()->json(['php_ini' => $this->ini($version, $php)]);
    }

    /**
     * The common settings of one version's php.ini, as fields. `null` where the file sets
     * nothing and PHP's built-in default applies.
     */
    public function showIniSettings(string $version, PhpVersionManager $php, PhpIniDirectives $directives): JsonResponse
    {
        return response()->json([
            'version' => $version,
            'settings' => $directives->read($php->readIni($version)),
        ]);
    }

    /**
     * Save those settings: only their lines change; written like the raw editor's save.
     */
    public function updateIniSettings(string $version, SavePhpIniSettingsRequest $request, PhpVersionManager $php, PhpIniDirectives $directives, ActivityLogger $log): JsonResponse
    {
        $changes = $request->changes();

        if ($changes !== []) {
            $php->writeIni($version, $directives->apply($php->readIni($version), $changes), $request->safetyCopy());
            $log->log('php.ini_updated', properties: ['version' => $version, 'safety_copy' => $request->safetyCopy()]);
        }

        return response()->json([
            'version' => $version,
            'settings' => $directives->read($php->readIni($version)),
        ]);
    }

    /**
     * Every extension available to a version, with its current state.
     */
    public function extensions(string $version, PhpExtensionManager $extensions, PhpRuntime $php): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        return response()->json([
            'extensions' => $extensions->catalog($version),
            // False on OpenLiteSpeed: installed extensions are always on; install still works.
            'toggle_supported' => $extensions->togglesExtensions(),
            'panel_required' => $version === $php->panelVersion() ? $extensions->panelRequired() : [],
        ]);
    }

    /**
     * Turn one extension on or off for a version.
     *
     * On installs the package if missing; off only unlinks it. Never purge: that can remove
     * `php8.4-common` with it and take every site down.
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

        // The name becomes an apt package and a path, so it must be one this server offers.
        $row = $extensions->find($version, $extension);

        abort_if($row === null, 404);

        if ($row['builtin']) {
            return response()->json(['message' => __('errors/php.extension_builtin', ['extension' => $extension])], 422);
        }

        $enabled = (bool) $request->validated('enabled');
        $properties = ['version' => $version, 'extension' => $extension];

        if (! $enabled) {
            // Only the panel's own version is restricted.
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
            // Recorded before dispatch, as for a version install.
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
     * The ionCube Loader card for one PHP version.
     *
     * State is read from the server, never stored: a stored flag would outlive the PHP version.
     * The in-flight and last-failed run come from the tracker the extension rows use.
     */
    public function ionCube(string $version, IonCubeLoader $loader, PhpRuntime $php, InstallTracker $installs): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        $run = $installs->current(InstallIonCubeLoader::RUNTIME, $version);

        return response()->json([
            'ioncube' => [
                ...$loader->status($version),
                'status' => $run?->status->value ?? 'idle',
                'reason' => $run?->reason,
                // `reason` is a code for the frontend, never user text.
                'message' => $loader->failureMessage($run, $version),
                'reference' => $run?->reference,
            ],
        ]);
    }

    /**
     * Install it. Queued (202): the ~29 MB archive would outlive the web server's request timeout.
     */
    public function installIonCube(string $version, IonCubeRequest $request, IonCubeLoader $loader, PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        // Refused before the row exists, so an unsupported version (PHP 8.0) leaves no
        // tracker row that never completes.
        if (! $loader->supports($version)) {
            return response()->json([
                'message' => __('errors/php.ioncube_unsupported_version', ['version' => $version]),
            ], 422);
        }

        // Refused here too, not only in the job: a queued install that can only fail is noise.
        $source = $loader->source($version);
        if ($source === IonCubeLoader::SOURCE_EXTERNAL || $source === IonCubeLoader::SOURCE_V7) {
            return response()->json([
                'message' => __($source === IonCubeLoader::SOURCE_V7 ? 'errors/php.ioncube_v7' : 'errors/php.ioncube_external', ['version' => $version]),
            ], 422);
        }

        // A second click must not queue a second download over the first.
        if ($installs->current(InstallIonCubeLoader::RUNTIME, $version)?->status === InstallStatus::Installing) {
            return response()->json(['ioncube' => $loader->status($version)], 202);
        }

        $installs->start(InstallIonCubeLoader::RUNTIME, $version);

        InstallIonCubeLoader::dispatch($version, Auth::id(), $request->safetyCopy());

        $log->log('php.ioncube_install_started', null, ['version' => $version]);

        return response()->json(['ioncube' => $loader->status($version)], 202);
    }

    /**
     * Remove it. Synchronous: it only deletes two files and reloads.
     */
    public function removeIonCube(string $version, IonCubeRequest $request, IonCubeLoader $loader, PhpRuntime $php, ActivityLogger $log): JsonResponse
    {
        abort_unless($php->installed($version), 404);

        $loader->remove($version, $request->safetyCopy());

        $log->log('php.ioncube_removed', null, ['version' => $version, 'safety_copy' => $request->safetyCopy()]);

        return response()->json(['ioncube' => $loader->status($version)]);
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
