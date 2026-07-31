<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;

class UpdateApplication
{
    public function __construct(private ActivityLogger $activityLogger) {}

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

        $application->update($data);

        $this->activityLogger->log('application.updated', $application, [
            'name' => $application->name,
        ]);

        return $application->fresh(['systemUser']);
    }
}
