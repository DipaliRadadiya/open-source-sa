<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\SavePhpSettingsRequest;
use App\Http\Resources\ApplicationPhpSettingsResource;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\ActivityLogger;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One site's PHP: its version, its limits, and the pool that enforces them.
 *
 * Nothing here can be enforced per-site until the site has a pool of its own —
 * a shared pool means a shared memory_limit — so isolation is the first thing
 * this screen offers and everything else follows it.
 */
class ApplicationPhpController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->load('phpSettings', 'systemUser'))->resolve(),
        ]);
    }

    /**
     * Give this site its own pool, running as its own user.
     *
     * Separate from saving settings because it is a different kind of change:
     * settings adjust a pool that exists, this one moves the site off the
     * shared pool and rewrites its vhost. It is also the only reversible-by-
     * design step — the shared pool keeps working throughout.
     */
    public function isolate(
        Request $request,
        Application $application,
        PoolManager $pools,
        WebServerManager $webServers,
        ActivityLogger $activity,
    ): JsonResponse {
        abort_unless($request->user()?->canManage('app_php') ?? false, 403);

        if (! $pools->supported()) {
            // OpenLiteSpeed spawns LSPHP itself and has no pools at all.
            throw ValidationException::withMessages([
                'application' => [__('php_settings.errors.unsupported_stack')],
            ]);
        }

        if ($application->isolated_at !== null) {
            throw ValidationException::withMessages([
                'application' => [__('php_settings.errors.already_isolated')],
            ]);
        }

        $settings = $this->settingsFor($application);

        $result = $pools->apply($application, $settings);

        if (! $result['ok']) {
            // Nothing was reloaded, so the site is still being served by the
            // shared pool exactly as it was a moment ago.
            throw ValidationException::withMessages([
                'application' => [__('php_settings.errors.'.$result['reason'])],
            ]);
        }

        $application->forceFill(['isolated_at' => now()])->save();

        // Vhost last: it can only point at the socket once the pool that owns
        // that socket is live, or the site 502s in the gap.
        $this->republish($application, $webServers);

        $activity->log('application.php_isolated', $application, ['name' => $application->name]);

        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->fresh(['phpSettings', 'systemUser']))->resolve(),
        ]);
    }

    public function update(
        SavePhpSettingsRequest $request,
        Application $application,
        PoolManager $pools,
        WebServerManager $webServers,
        ActivityLogger $activity,
    ): JsonResponse {
        $data = $request->validated();
        $version = $data['php_version'] ?? null;
        unset($data['php_version']);

        $settings = $this->settingsFor($application);
        $settings->fill($data);

        // The version is on the application rather than the settings row: the
        // vhost and the pool path both depend on it, and it existed before
        // this feature did.
        if ($version !== null && $version !== $application->php_version) {
            $previousVersion = $application->php_version;
            $application->forceFill(['php_version' => $version])->save();

            // A version change moves the pool between directories, so the old
            // one has to go or the site keeps a pool it no longer uses,
            // holding memory and a socket nobody talks to.
            if ($application->isolated_at !== null && $previousVersion !== null) {
                $pools->remove($application, $previousVersion);
            }
        }

        if ($application->isolated_at !== null) {
            $result = $pools->apply($application, $settings);

            if (! $result['ok']) {
                throw ValidationException::withMessages([
                    'settings' => [__('php_settings.errors.'.$result['reason'])],
                ]);
            }
        }

        $settings->save();

        if ($version !== null) {
            $this->republish($application, $webServers);
        }

        $activity->log('application.php_settings_updated', $application, ['name' => $application->name]);

        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->fresh(['phpSettings', 'systemUser']))->resolve(),
        ]);
    }

    /** Put the site back on the shared pool. The way out if isolation broke it. */
    public function unisolate(
        Request $request,
        Application $application,
        PoolManager $pools,
        WebServerManager $webServers,
        ActivityLogger $activity,
    ): JsonResponse {
        abort_unless($request->user()?->canManage('app_php') ?? false, 403);

        if ($application->isolated_at === null) {
            throw ValidationException::withMessages([
                'application' => [__('php_settings.errors.not_isolated')],
            ]);
        }

        // Vhost first this time, for the mirror-image reason: the site must
        // stop pointing at the socket before the pool serving it is removed.
        $application->forceFill(['isolated_at' => null])->save();
        $this->republish($application, $webServers);

        $pools->remove($application);

        $activity->log('application.php_unisolated', $application, ['name' => $application->name]);

        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->fresh(['phpSettings', 'systemUser']))->resolve(),
        ]);
    }

    /** Rewrite this site's vhost and reload — tested before it is applied. */
    private function republish(Application $application, WebServerManager $webServers): void
    {
        $driver = $webServers->driver();
        $home = rtrim((string) $application->systemUser?->home_path, '/');
        $webRoot = trim((string) ($application->web_root ?: '/'), '/');
        $root = "{$home}/{$application->domain}".($webRoot === '' ? '' : '/'.$webRoot);

        $driver->apply($application, $root);

        if ($driver->test()->ok) {
            $driver->reload();
        }
    }

    private function settingsFor(Application $application): ApplicationPhpSettings
    {
        return $application->phpSettings ?? new ApplicationPhpSettings([
            'application_id' => $application->id,
        ]);
    }
}
