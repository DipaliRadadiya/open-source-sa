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

        // Drop the DB's owned users first (no orphans), then the database.
        foreach ($database->users as $user) {
            $engine->dropUser($user->username, $user->host, $database->name);
        }

        $engine->dropDatabase($database->name);

        $this->activityLogger->log('database.deleted', null, ['name' => $database->name, 'engine' => $database->engine]);

        $database->delete(); // cascade removes database_users rows
    }
}
