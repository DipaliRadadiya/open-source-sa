<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\SavePhpSettingsRequest;
use App\Http\Requests\Server\Application\TranslateV7ExtraConfRequest;
use App\Http\Resources\ApplicationPhpSettingsResource;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\ActivityLogger;
use App\Services\Server\Cron\CronPhpBinary;
use App\Services\Server\Php\PoolIsolator;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\Php\V7PhpExtraConf;
use App\Support\ActivityChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One site's PHP: its version, its limits, and the pool that enforces them.
 *
 * Limits cannot be enforced per site until the site has its own pool, so isolation comes first.
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
     * Provisioning already does this for sites the panel creates; this repairs the others (adopted,
     * made before isolation, or left behind by a failed pool step). There is no way back onto the
     * shared pool: it would let one compromised site read every other site's `.env`.
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

        // True when the settings live somewhere other than a pool file: OpenLiteSpeed has no pools,
        // so each site gets its own ini written with its vhost. {@see SitePhpIni}
        $appliedWithoutPool = ! $isolator->supported();

        // These values are enforced by the pool file. Without a pool or another carrier they would
        // be stored and never applied, so refuse. The version is exempt: the vhost carries it.
        if ($data !== [] && $application->isolated_at === null && ! $appliedWithoutPool) {
            throw ValidationException::withMessages([
                'settings' => [__('php_settings.errors.needs_isolation')],
            ]);
        }

        $settings = $this->settingsFor($application);
        // For the activity entry: the values in force (an unset limit is the pool's default).
        $inForce = fn () => ['php_version' => $application->php_version]
            + array_intersect_key($settings->effective(), $data)
            + $settings->only(array_keys($data));
        $before = $inForce();
        $settings->fill($data);

        // The version lives on the application (the vhost and the pool path depend on it). Nothing
        // is persisted until the pool for the new version is live: a failed `apply()` must leave
        // the site on the version it was already serving, with its old pool.
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

            // Only now: a version change moves the pool between directories, so the old one goes,
            // but never before its replacement exists.
            if ($application->isolated_at !== null && $previousVersion !== null) {
                $pools->remove($application, $previousVersion);
            }
        }

        $settings->save();

        // Re-applied whenever anything changed, not only on a version change: on a stack with no
        // pools the driver writes the site's ini while rendering the vhost, so this is the only way
        // the settings reach the server. `post_max_size` is included because the vhost carries the
        // request-body limit too; otherwise the web server would keep refusing uploads at the old value.
        $bodyLimitChanged = array_key_exists('post_max_size', $data);

        if ($version !== null || $bodyLimitChanged || ($appliedWithoutPool && $data !== [])) {
            $isolator->republish($application);
        }

        $activity->log('application.php_settings_updated', $application, [
            'name' => $application->name,
            'changes' => ActivityChanges::between(
                $before,
                $inForce(),
                ['php_version', ...array_keys($data)],
            ),
        ]);

        return response()->json([
            'php' => ApplicationPhpSettingsResource::make($application->fresh(['phpSettings', 'systemUser']))->resolve(),
            // The site's cron jobs not on its PHP: offered, not changed; the user picks which to switch.
            'cronjobs_to_review' => $application->php_version
                ? app(CronPhpBinary::class)->review($application, (string) $application->php_version)
                : [],
        ]);
    }

    private function settingsFor(Application $application): ApplicationPhpSettings
    {
        return $application->phpSettings ?? new ApplicationPhpSettings([
            'application_id' => $application->id,
        ]);
    }

    /**
     * A v7 site's "extra pool lines" as the body of a PHP settings save
     * (v7 importer helper). Saves nothing: the caller sends `settings` to
     * PUT /php, the one path that tests the pool before it reloads.
     *
     * `valid`/`errors` are the PHP screen's own rules run on that body now,
     * so a preview never promises a save that will be refused.
     */
    public function translateV7(TranslateV7ExtraConfRequest $request, Application $application, V7PhpExtraConf $translator, PoolIsolator $isolator): JsonResponse
    {
        $result = $translator->translate((string) $request->validated('extra_conf'), $application->loadMissing('systemUser'), fpm: $isolator->supported());

        $check = SavePhpSettingsRequest::create($request->url(), 'PUT', $result['settings']);
        $check->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $request->user());
        $check->forApplication = $application;

        $errors = [];

        try {
            $check->validateResolved();
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        return response()->json([
            'settings' => $result['settings'],
            'refused' => array_map(fn (array $r): array => $r + [
                'message' => __('php_settings.errors.'.$r['reason'], ['line' => mb_strimwidth($r['line'], 0, 80, '…'), 'name' => $r['name']]),
            ], $result['refused']),
            'valid' => $errors === [],
            'errors' => $errors,
        ]);
    }
}
