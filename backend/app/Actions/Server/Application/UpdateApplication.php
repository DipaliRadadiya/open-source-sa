<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;

class UpdateApplication
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private UpdateApplicationWebRoot $webRootAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Application $application, array $data): Application
    {
        // Merge rather than replace: a partial update must not wipe the answers it omits.
        if (array_key_exists('settings', $data)) {
            // A password sent here is for the installer (a retry after a failed install), so it
            // goes where the installer reads it, not into the `settings` the API returns.
            $incoming = array_diff_key((array) $data['settings'], array_flip(Application::INSTALLER_RECORDED_KEYS));
            $secrets = array_intersect_key($incoming, array_flip(Application::INSTALL_SECRET_KEYS));

            $data['settings'] = array_merge($application->settings ?? [], array_diff_key($incoming, $secrets));

            if ($secrets !== []) {
                $data['install_secrets'] = array_merge($application->install_secrets ?? [], $secrets);
            }
        }

        // The slug is never recomputed here: {@see UpdateApplicationRequest} refuses `name`.
        // The slug also names the PHP-FPM pool and socket, the site directory, logs, fail2ban
        // jail and worker units, so regenerating it would only half-rename the site.

        // Changing the rendering type or start command changes how the site must be served.
        if (array_key_exists('rendering_type', $data) || array_key_exists('start_command', $data)) {
            $data['serving_profile'] = ServingProfile::resolve(
                app(SiteTypeManager::class)->find($application->site_type),
                $data,
                $application->serving_profile,
            );

            // Drop the leftovers: a stale start command makes the UI offer controls for a unit
            // that isn't there, and a held port blocks the next app that asks for one.
            if ($data['serving_profile'] !== 'node') {
                $data['start_command'] = null;
                $data['app_port'] = null;
            }
        }

        // The web root is not a plain column write: it moves the served directory, so it goes
        // through the action that also rewrites the vhost, the pool and the unit.
        $webRoot = $data['web_root'] ?? null;
        $changesWebRoot = array_key_exists('web_root', $data);
        unset($data['web_root']);

        $application->forceFill($data)->save();

        if ($changesWebRoot) {
            $this->webRootAction->execute($application, $webRoot);
        }

        $this->activityLogger->log('application.updated', $application, [
            'name' => $application->name,
        ]);

        return $application->fresh(['systemUser']);
    }
}
