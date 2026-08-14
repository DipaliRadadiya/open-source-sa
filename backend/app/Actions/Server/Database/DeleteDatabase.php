<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;

class DeleteDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Database $database): void
    {
        $engine = $this->manager->engine($database->engine);

        // Keep the database and its credentials together until the database
        // drop has succeeded. Removing users first can leave a live database
        // unreachable if its drop then fails.
        $engine->dropDatabase($database->name);

        // A failed user cleanup deliberately leaves the panel record intact.
        // SQL drops are idempotent, so a retry can finish cleanup safely.
        foreach ($database->users as $user) {
            $engine->dropUser($user->username, $user->host, $database->name);
        }

        $this->activityLogger->log('database.deleted', null, ['name' => $database->name, 'engine' => $database->engine]);

        $database->delete(); // cascade removes database_users rows
    }
}
