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
        // Merge rather than replace: a partial settings update must not wipe
        // the answers it didn't mention.
        if (array_key_exists('settings', $data)) {
            // A password sent here is for the installer (a retry after a
            // failed install), so it goes where the installer reads it, not
            // into the plain `settings` the API returns.
            $incoming = array_diff_key((array) $data['settings'], array_flip(Application::INSTALLER_RECORDED_KEYS));
            $secrets = array_intersect_key($incoming, array_flip(Application::INSTALL_SECRET_KEYS));

            $data['settings'] = array_merge($application->settings ?? [], array_diff_key($incoming, $secrets));

            if ($secrets !== []) {
                $data['install_secrets'] = array_merge($application->install_secrets ?? [], $secrets);
            }
        }

        // The slug is not recomputed here, and a rename cannot reach this
        // method any more — {@see UpdateApplicationRequest} refuses `name`.
        //
        // What used to be here regenerated the slug and moved the vhost to
        // match it. That was half a rename: the slug also names the PHP-FPM
        // pool and its socket, the site's directory, its logs, its fail2ban
        // jail and its worker units, and none of those moved. On a real
        // server the result was a vhost pointing at a socket no pool listens
        // on (502) beside a freshly created *empty* site directory (404),
        // with the site's actual files still in the old one.
        //
        // Nothing is left to detect, so there is no `$renamed` flag and no
        // config move below.

        // Changing the rendering type or the start command changes how the
        // site must be served, and getting it wrong is invisible until the
        // site is live.
        if (array_key_exists('rendering_type', $data) || array_key_exists('start_command', $data)) {
            $data['serving_profile'] = ServingProfile::resolve(
                app(SiteTypeManager::class)->find($application->site_type),
                $data,
                $application->serving_profile,
            );

            // A site that no longer runs anything must not keep the leftovers:
            // a stale start command makes the UI offer process controls for a
            // unit that isn't there, and a held port blocks the next app that
            // asks for one.
            if ($data['serving_profile'] !== 'node') {
                $data['start_command'] = null;
                $data['app_port'] = null;
            }
        }

        // The web root is not a plain column write: it moves the directory the
        // site is served from, so it goes through the manager that also
        // rewrites the vhost, the pool and the unit. Left here rather than
        // rejected, so an existing caller sending the whole application form
        // gets the change applied instead of stored and ignored.
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
