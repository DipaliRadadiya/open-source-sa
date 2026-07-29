<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Services\ActivityLogger;

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

        $application->update($data);

        $this->activityLogger->log('application.updated', $application, [
            'name' => $application->name,
        ]);

        return $application->fresh(['systemUser']);
    }
}
