<?php

namespace App\Actions\Server\Database;

use App\Models\Application;
use App\Models\Database;
use App\Services\ActivityLogger;

/**
 * Point a database at an application, or at nothing.
 *
 * 🔴 This changes bookkeeping, not connectivity. Nothing here rewrites
 * `wp-config.php`, an `.env` or any other connection string — an application
 * keeps talking to whatever its own code says it talks to. What the link
 * decides is which database the panel treats as part of the application:
 *
 * - `Backups\Steps\DumpDatabase` dumps exactly the attached databases, so an
 *   unattached one is absent from every backup of that site, silently.
 * - Staging, cloning and restoring each find the application's database this
 *   way.
 *
 * That is why this exists at all: before it, the link could only be set in the
 * request that created the database, so a database created on its own — or
 * adopted from a brownfield server — could never be backed up with its site.
 */
class AttachDatabaseToApplication
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function execute(Database $database, ?Application $application): Database
    {
        $previous = $database->application;

        $database->update(['application_id' => $application?->id]);

        if ($application !== null) {
            $this->activityLogger->log('database.attached', $database, [
                'name' => $database->name,
                'application' => $application->name,
            ]);
        } elseif ($previous !== null) {
            $this->activityLogger->log('database.detached', $database, [
                'name' => $database->name,
                'application' => $previous->name,
            ]);
        }

        return $database->fresh(['users']);
    }
}
