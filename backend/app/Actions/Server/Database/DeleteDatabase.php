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

        // Teardown order — and which statements it takes — belongs to the
        // engine. This action had both inlined, in the order MySQL needs;
        // PostgreSQL needs the same order and different statements, and
        // discovering that here would mean an `if` on the engine name.
        //
        // A failed cleanup deliberately leaves the panel record intact, so the
        // same delete can be retried until it finishes.
        $engine->teardownDatabase($database->name, $database->users
            ->map(fn ($user) => ['username' => $user->username, 'host' => $user->host])
            ->all());

        $this->activityLogger->log('database.deleted', null, ['name' => $database->name, 'engine' => $database->engine]);

        $database->delete(); // cascade removes database_users rows
    }
}
