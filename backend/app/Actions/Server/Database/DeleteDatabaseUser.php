<?php

namespace App\Actions\Server\Database;

use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;

class DeleteDatabaseUser
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(DatabaseUser $user): void
    {
        $database = $user->database;
        $engine = $this->manager->engine($database->engine);

        $engine->dropUser($user->username, $user->host, $database->name);

        $this->activityLogger->log('database.user_deleted', null, [
            'username' => $user->username,
            'database' => $database->name,
        ]);

        $user->delete();
    }
}
