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
 * Databases were already adoptable; their users were not, so an adopted
 * database showed an empty user list — which reads as "nobody can connect to
 * this", while four applications were connecting to it perfectly well.
 *
 * The password is the thing this cannot recover. Engines store a hash, and a
 * hash is not a password. So the row is created with a null one and the API
 * says `password_known: false` rather than handing out a connection string
 * that looks right and does not work. Setting a new password is a deliberate
 * act, because it breaks every application still using the old one.
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
        // A user is scoped to a database in this panel's model, so there has
        // to be one to attach it to. Databases have their own adopt endpoint
        // rather than a discoverer, so this depends on nothing — but the
        // database rows must already exist, which the check below enforces
        // by simply finding nothing to do.
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

            // Only databases the panel already tracks. A user on an unadopted
            // database has nothing to hang off, and adopting the database is
            // the step that should come first.
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
                        // The account and its grant are facts read from the
                        // engine. Nothing here is inferred.
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
                // Null, not an empty string. The column is nullable precisely
                // so this can be honest — see the migration.
                'password' => null,
                'connection_preference' => $this->preferenceFor($attributes['host']),
            ],
        );
    }

    /**
     * What the stored host means in the panel's own vocabulary.
     *
     * Read from the grant rather than assumed: a user granted from `%` can
     * connect from anywhere, and recording that as `localhost` would describe
     * the account as narrower than it is — the wrong direction for anything
     * anyone might later audit.
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
