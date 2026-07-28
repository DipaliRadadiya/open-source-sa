<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * Brownfield reconcile: bring existing server databases (from a migrated
 * server) under panel management. Never drops or alters — only records.
 */
class AdoptDatabases
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, Database>
     */
    public function execute(string $engineName, array $names): Collection
    {
        $engine = $this->manager->engine($engineName);
        $existingOnServer = $engine->listDatabases();

        $adopted = collect($names)
            ->unique()
            ->reject(fn (string $name) => $this->manager->isSystemDatabase($engineName, $name))
            ->filter(fn (string $name) => in_array($name, $existingOnServer, true))
            ->reject(fn (string $name) => Database::query()->where('engine', $engineName)->where('name', $name)->exists())
            ->map(function (string $name) use ($engineName, $engine) {
                $database = Database::create([
                    'name' => $name,
                    'engine' => $engineName,
                    'size_bytes' => $engine->databaseSize($name),
                ]);

                $this->activityLogger->log('database.imported', $database, ['name' => $name, 'engine' => $engineName]);

                return $database;
            })
            ->values();

        return $adopted;
    }
}
