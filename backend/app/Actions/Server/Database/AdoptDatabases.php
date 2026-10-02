<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Sync\Discoverers\DatabaseUserDiscoverer;
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
        private DatabaseUserDiscoverer $users,
    ) {}

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, Database>
     */
    public function execute(string $engineName, array $names): Collection
    {
        $engine = $this->manager->engine($engineName);
        $existingOnServer = $engine->listDatabases();
        // Asked once for the whole batch; see adoptUsers().
        $engineUsers = null;

        $adopted = collect($names)
            ->unique()
            ->reject(fn (string $name) => $this->manager->isSystemDatabase($engineName, $name))
            ->filter(fn (string $name) => in_array($name, $existingOnServer, true))
            ->reject(fn (string $name) => Database::query()->where('engine', $engineName)->where('name', $name)->exists())
            ->map(function (string $name) use ($engineName, $engine, &$engineUsers) {
                // Asked, not guessed. The panel records charset and collation
                // when it *creates* a database and recorded neither when it
                // adopted one, so every database brought over from another
                // panel showed a blank charset forever beside panel-created
                // ones that showed theirs — on the screen that exists for
                // migrated servers. The engine knew all along.
                $described = $engine->describeDatabase($name);

                $database = Database::create([
                    'name' => $name,
                    'engine' => $engineName,
                    'charset' => $described['charset'],
                    'collation' => $described['collation'],
                    'size_bytes' => $engine->databaseSize($name),
                ]);

                $engineUsers ??= $engine->listUsers();
                $this->adoptUsers($database, $engineUsers);

                $this->activityLogger->log('database.imported', $database, ['name' => $name, 'engine' => $engineName]);

                return $database;
            })
            ->values();

        return $adopted;
    }

    /**
     * Record the accounts that already have rights on an adopted database
     * (bug #34): it showed 0 users, and deleting it left them behind with
     * their rights. The same record Server Sync makes for a user, through the
     * same code — no password (the engine holds only a hash).
     *
     * @param  array<int, array{username: string, host: string, databases: array<int, string>}>  $engineUsers
     */
    private function adoptUsers(Database $database, array $engineUsers): void
    {
        foreach ($engineUsers as $user) {
            if (in_array($database->name, $user['databases'], true)) {
                $this->users->adopt(['attributes' => [
                    'database_id' => $database->id,
                    'username' => $user['username'],
                    'host' => $user['host'],
                ]]);
            }
        }
    }
}
