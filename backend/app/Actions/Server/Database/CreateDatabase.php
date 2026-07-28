<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Support\Facades\Cache;

class CreateDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private CreateDatabaseUser $createUser,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{name: string, engine: string, charset?: ?string, collation?: ?string, application_id?: ?int, create_user?: array<string, mixed>|null}  $data
     */
    public function execute(array $data): Database
    {
        $engineName = $data['engine'];

        return Cache::lock("database:create:{$engineName}:{$data['name']}", 15)->block(5, function () use ($data, $engineName) {
            $engine = $this->manager->engine($engineName);
            $charset = $data['charset'] ?? null;
            $collation = $data['collation'] ?? null;

            $engine->createDatabase($data['name'], $charset, $collation);

            $database = Database::create([
                'name' => $data['name'],
                'engine' => $engineName,
                'charset' => $charset,
                'collation' => $collation,
                'application_id' => $data['application_id'] ?? null,
                'size_bytes' => $engine->databaseSize($data['name']),
            ]);

            $this->activityLogger->log('database.created', $database, ['name' => $data['name'], 'engine' => $engineName]);

            if (! empty($data['create_user'])) {
                $this->createUser->execute($database, $data['create_user']);
            }

            return $database->load('users');
        });
    }
}
