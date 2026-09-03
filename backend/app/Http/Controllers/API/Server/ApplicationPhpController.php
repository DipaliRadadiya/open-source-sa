<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\SavePhpSettingsRequest;
use App\Http\Resources\ApplicationPhpSettingsResource;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\ActivityLogger;
use App\Services\Server\Php\PoolIsolator;
use App\Services\Server\Php\PoolManager;
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
     * Provisioning already does this for every site the panel creates, so
     * this exists for the ones it did not: adopted from another panel, made
     * before isolation shipped, or left behind by a failed pool step. It is a
     * repair, not a mode — there is no supported way back onto the shared
     * pool, because that means running as the web server's own account again
     * and letting one compromised site read every other site's `.env`.
     */
    public function isolate(
        Request $request,
        Application $application,
        PoolIsolator $isolator,
        ActivityLogger $activity,
    ): JsonResponse {
        abort_unless($request->user()?->canManage('app_php') ?? false, 403);

        if (! $isolator->supported()) {
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

        $result = $isolator->isolate($application);

        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'application' => [__('php_settings.errors.'.$result['reason'])],
            ]);
        }

        $activity->log('application.php_isolated', $application, ['name' => $application->name]);

        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->fresh(['phpSettings', 'systemUser']))->resolve(),
        ]);
    }

    public function update(
        SavePhpSettingsRequest $request,
        Application $application,
        PoolManager $pools,
        PoolIsolator $isolator,
        ActivityLogger $activity,
    ): JsonResponse {
        $data = $request->validated();
        $version = $data['php_version'] ?? null;
        unset($data['php_version']);

        // Whether this server carries a site's PHP settings somewhere other
        // than a pool file. True on OpenLiteSpeed, which has no pools: each
        // site gets its own ini, written with its vhost. {@see SitePhpIni}
        //
        // The guard below used to read `$isolator->supported()` directly,
        // which asks "does this stack have pools" — false on OLS, so the guard
        // was switched off on the one stack where nothing else applied these
        // values either. Every setting saved with a 200 and reached nothing.
        $appliedWithoutPool = ! $isolator->supported();

        // Every value below is enforced by the pool file. Without a pool, and
        // without another way to carry them, they would be stored and never
        // applied — the user changes memory_limit, gets a 200 and nothing on
        // the server moves. Say so instead. The version is exempt: it is
        // carried by the vhost and means something either way.
        if ($data !== [] && $application->isolated_at === null && ! $appliedWithoutPool) {
            throw ValidationException::withMessages([
                'settings' => [__('php_settings.errors.needs_isolation')],
            ]);
        }

        $settings = $this->settingsFor($application);
        $settings->fill($data);

        // The version is on the application rather than the settings row: the
        // vhost and the pool path both depend on it, and it existed before
        // this feature did.
        //
        // Nothing here is persisted until the pool that the new version names
        // is actually live. It used to save the version first and delete the
        // old pool second, so a failing `apply()` returned 422 having already
        // written the new version to the database and removed the only pool
        // the site had: the panel then showed a version that was not running,
        // on a site answering 502, with its settings unsaved and its vhost
        // still pointed at the old socket. A failure must leave the site on
        // the version it was already serving.
        $previousVersion = $application->php_version;
        $versionChanged = $version !== null && $version !== $previousVersion;

        if ($versionChanged) {
            // In memory only — `apply()` derives the pool path from it.
            $application->php_version = $version;
        }

        if ($application->isolated_at !== null) {
            $result = $pools->apply($application, $settings);

            if (! $result['ok']) {
                $application->php_version = $previousVersion;

                throw ValidationException::withMessages([
                    'settings' => [__('php_settings.errors.'.$result['reason'])],
                ]);
            }
        }

        if ($versionChanged) {
            $application->forceFill(['php_version' => $version])->save();

            // Only now. A version change moves the pool between directories,
            // so the old one has to go or the site keeps a pool it no longer
            // uses, holding memory and a socket nobody talks to — but removing
            // it before the replacement exists is what left the site with
            // none at all.
            if ($application->isolated_at !== null && $previousVersion !== null) {
                $pools->remove($application, $previousVersion);
            }
        }

        $settings->save();

        // Re-applied whenever anything changed, not only on a version change.
        // On a stack with no pools this is what puts the settings on the
        // server at all: the driver writes the site's ini in the same pass it
        // renders the vhost, so re-applying is the whole mechanism. On FPM the
        // pool above already did the work and this republishes the vhost for a
        // version change exactly as before.
        if ($version !== null || ($appliedWithoutPool && $data !== [])) {
            $isolator->republish($application);
        }

        $activity->log('application.php_settings_updated', $application, ['name' => $application->name]);

        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->fresh(['phpSettings', 'systemUser']))->resolve(),
        ]);
    }

    private function settingsFor(Application $application): ApplicationPhpSettings
    {
        return $application->phpSettings ?? new ApplicationPhpSettings([
            'application_id' => $application->id,
        ]);
    }
}
