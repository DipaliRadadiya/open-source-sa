<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;
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

        // Adding or removing a start command changes how the site must be
        // served: a proxy vhost with nothing behind it is a 502, and a
        // directory vhost for an app that routes in code serves its source.
        if (array_key_exists('start_command', $data)) {
            $data['serving_profile'] = filled($data['start_command'])
                ? 'node'
                : app(SiteTypeManager::class)->find($application->site_type)?->servingProfile()
                    ?? $application->serving_profile;
        }

        $application->update($data);

        $this->activityLogger->log('application.updated', $application, [
            'name' => $application->name,
        ]);

        return $application->fresh(['systemUser']);
    }
}
