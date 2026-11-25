<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\SyncRun;
use App\Services\Server\Databases\DatabaseManager;

/**
 * Accounts that can reach a database the panel now tracks.
 *
 * The password cannot be recovered: engines store a hash. The row is created with a null
 * password and the API says `password_known: false` instead of handing out a connection string
 * that does not work. Setting a new password is deliberate, because it breaks every application
 * still using the old one.
 */
class DatabaseUserDiscoverer implements Discoverable
{
    public function __construct(private DatabaseManager $databases) {}

    public function resourceType(): string
    {
        return 'database_user';
    }

    public function dependsOn(): array
    {
        // A user is scoped to a database, which has its own adopt endpoint rather than a
        // discoverer; so nothing is declared here and untracked databases simply yield nothing.
        return [];
    }

    public function discover(SyncRun $run): array
    {
        $found = [];

        foreach ($this->databases->engineNames() as $engineName) {
            $engine = $this->databases->engine($engineName);

            if (! $engine->available()) {
                continue;
            }

            // Only databases the panel already tracks; adopting the database comes first.
            $tracked = Database::query()
                ->where('engine', $engineName)
                ->pluck('id', 'name');

            if ($tracked->isEmpty()) {
                continue;
            }

            foreach ($engine->listUsers() as $user) {
                foreach ($user['databases'] as $databaseName) {
                    $databaseId = $tracked->get($databaseName);

                    if ($databaseId === null) {
                        continue;
                    }

                    $exists = DatabaseUser::query()
                        ->where('database_id', $databaseId)
                        ->where('username', $user['username'])
                        ->where('host', $user['host'])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $found[] = [
                        'key' => $engineName.':'.$user['username'].'@'.$user['host'].':'.$databaseName,
                        'label' => $user['username'].'@'.$user['host'],
                        // Read from the engine, not inferred.
                        'confidence' => 100,
                        'evidence' => [
                            'engine' => $engineName,
                            'database' => $databaseName,
                            'username' => $user['username'],
                            'host' => $user['host'],
                            // The one thing the screen has to say out loud.
                            'password_recoverable' => false,
                        ],
                        'attributes' => [
                            'database_id' => $databaseId,
                            'username' => $user['username'],
                            'host' => $user['host'],
                        ],
                    ];
                }
            }
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return DatabaseUser::firstOrCreate(
            [
                'database_id' => $attributes['database_id'],
                'username' => $attributes['username'],
                'host' => $attributes['host'],
            ],
            [
                // Null, not an empty string: the column is nullable for exactly this.
                'password' => null,
                'connection_preference' => $this->preferenceFor($attributes['host']),
            ],
        );
    }

    /**
     * What the stored host means in the panel's own vocabulary.
     *
     * Read from the grant: a user granted from `%` can connect from anywhere, and recording it as
     * `localhost` would understate its reach.
     */
    private function preferenceFor(string $host): string
    {
        return match (true) {
            $host === '%' => 'anywhere',
            $host === 'localhost', $host === '127.0.0.1' => 'localhost',
            default => 'remote',
        };
    }
}
