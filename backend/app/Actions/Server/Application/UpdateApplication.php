<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\WebServers\WebServerManager;

class UpdateApplication
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private UpdateApplicationWebRoot $webRootAction,
        private WebServerManager $webServers,
        private ApplyVhost $vhost,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Application $application, array $data): Application
    {
        // Merge rather than replace: a partial settings update must not wipe
        // the answers it didn't mention.
        if (array_key_exists('settings', $data)) {
            $data['settings'] = array_merge($application->settings ?? [], (array) $data['settings']);
        }

        // A rename moves the web-server config, because the file is named after
        // the application. The old one has to go while the application still
        // knows what it was called — `configPath()` is built from the slug, so
        // once that moves there is no way left to address the old file, and it
        // would sit in sites-enabled serving the same domains as the new one.
        //
        // Same ordering as ChangePrimaryDomain, and for the same reason.
        $renamed = array_key_exists('name', $data)
            && (string) $data['name'] !== (string) $application->name;

        if ($renamed) {
            $data['slug'] = Application::uniqueSlug((string) $data['name'], $application->id);
        }

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

        // Only a provisioned site has a config to move; a pending one has
        // nothing on disk yet, and a disabled one's vhost deliberately points
        // at the disabled page — republishing it here would put the site back
        // online as a side effect of a rename.
        $movesConfig = $renamed
            && $application->status === ApplicationStatus::Active
            && $application->disabled_at === null;

        if ($movesConfig) {
            $this->webServers->driver()->remove($application->load('systemUser'));
        }

        $application->forceFill($data)->save();

        if ($movesConfig) {
            $this->vhost->execute($application->fresh(['systemUser', 'domains']));
        }

        if ($changesWebRoot) {
            $this->webRootAction->execute($application, $webRoot);
        }

        $this->activityLogger->log('application.updated', $application, [
            'name' => $application->name,
        ]);

        return $application->fresh(['systemUser']);
    }
}
