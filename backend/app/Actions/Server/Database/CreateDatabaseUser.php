<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseFirewall;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\Databases\RemoteAccessPreparer;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateDatabaseUser
{
    public function __construct(
        private DatabaseManager $manager,
        private DatabaseFirewall $firewall,
        private RemoteAccessPreparer $remoteAccess,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{username: string, password?: ?string, connection_preference?: string, host?: ?string, restart_cluster?: bool}  $data
     */
    public function execute(Database $database, array $data): DatabaseUser
    {
        $preference = $data['connection_preference'] ?? 'localhost';
        $host = $this->resolveHost($preference, $data['host'] ?? null);
        $password = ($data['password'] ?? null) ?: DatabasePassword::generate();

        // Before the engine is touched. On PostgreSQL a remote grant needs the
        // cluster listening off-box, and that needs a restart the caller has to
        // agree to — refusing here leaves nothing half-made.
        $this->remoteAccess->prepare($database, $preference, (bool) ($data['restart_cluster'] ?? false));

        $engine = $this->manager->engine($database->engine);
        $engineUserCreated = false;

        try {
            $engine->createUser($data['username'], $host, $password, $database->name);
            $engineUserCreated = true;

            $this->firewall->sync($database->engine, $preference, $host);

            $user = $database->users()->create([
                'username' => $data['username'],
                'password' => $password,
                'connection_preference' => $preference,
                'host' => $host,
            ]);

            $this->activityLogger->log('database.user_created', $user, [
                'username' => $data['username'],
                'database' => $database->name,
            ]);

            return $user;
        } catch (Throwable $exception) {
            if ($engineUserCreated) {
                try {
                    $engine->dropUser($data['username'], $host, $database->name);
                } catch (Throwable $cleanupException) {
                    Log::warning('database user cleanup after failed create also failed', [
                        'database' => $database->name,
                        'username' => $data['username'],
                        'exception' => $cleanupException::class,
                    ]);
                }
            }

            throw $exception;
        }
    }

    private function resolveHost(string $preference, ?string $host): string
    {
        return match ($preference) {
            'anywhere' => '%',
            'remote' => (string) $host,
            default => 'localhost',
        };
    }
}
