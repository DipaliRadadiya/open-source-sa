<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\RemoteAccessPreparer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private CreateDatabaseUser $createUser,
        private ActivityLogger $activityLogger,
        private RemoteAccessPreparer $remoteAccess,
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

            if (! empty($data['create_user'])) {
                $this->createUser->ensureUsernameFree($engineName, $data['name'], $data['create_user'], 'create_user.username');

                // FS-C14(e): a remote user that needs the cluster restarted
                // was refused only after the database had been made — so
                // every round created it, dropped it again, and logged a
                // `database.created` for a database that no longer existed.
                // Asked first; the user's own step then finds it settled.
                $this->remoteAccess->prepare(
                    new Database(['name' => $data['name'], 'engine' => $engineName]),
                    (string) ($data['create_user']['connection_preference'] ?? 'localhost'),
                    (bool) ($data['create_user']['restart_cluster'] ?? false),
                );
            }

            $engine->createDatabase($data['name'], $charset, $collation);

            // Left blank, the engine picked its own default — record what it
            // picked, or the screen shows an empty charset for every database
            // made without one (seen on the nginx test box: MariaDB used
            // utf8mb4, the panel said nothing). Same question AdoptDatabases
            // asks; a failed answer leaves the field as it was.
            if ($charset === null || $collation === null) {
                $described = $engine->describeDatabase($data['name']);
                $charset ??= $described['charset'];
                $collation ??= $described['collation'];
            }

            $database = null;

            try {
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
            } catch (Throwable $exception) {
                // This request created the schema. If its requested initial
                // user cannot be completed, remove both sides so retrying is
                // safe rather than leaving an invisible partial database.
                $database?->delete();

                try {
                    $engine->dropDatabase($data['name']);
                } catch (Throwable $cleanupException) {
                    Log::warning('database cleanup after failed create also failed', [
                        'database' => $data['name'],
                        'engine' => $engineName,
                        'exception' => $cleanupException::class,
                    ]);
                }

                throw $exception;
            }
        });
    }
}
