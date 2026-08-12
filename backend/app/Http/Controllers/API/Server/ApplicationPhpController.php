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

        // Every value below is enforced by the pool file. Without a pool they
        // would be stored and never applied — the user changes memory_limit,
        // gets a 200 and nothing on the server moves. Say so instead. The
        // version is exempt: it is carried by the vhost and means something
        // either way.
        if ($data !== [] && $application->isolated_at === null && $isolator->supported()) {
            throw ValidationException::withMessages([
                'settings' => [__('php_settings.errors.needs_isolation')],
            ]);
        }

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
