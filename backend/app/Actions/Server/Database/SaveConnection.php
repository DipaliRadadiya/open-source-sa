<?php

namespace App\Actions\Server\Database;

use App\Models\DatabaseConnection;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;

class SaveConnection
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(string $engine, array $data): DatabaseConnection
    {
        $connection = $this->manager->connection($engine);
        $connection->update($data);

        $this->activityLogger->log('database.connection_updated', $connection, ['engine' => $engine]);

        return $connection->refresh();
    }
}
