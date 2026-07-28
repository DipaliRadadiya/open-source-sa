<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseFirewall;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;

class CreateDatabaseUser
{
    public function __construct(
        private DatabaseManager $manager,
        private DatabaseFirewall $firewall,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{username: string, password?: ?string, connection_preference?: string, host?: ?string}  $data
     */
    public function execute(Database $database, array $data): DatabaseUser
    {
        $preference = $data['connection_preference'] ?? 'localhost';
        $host = $this->resolveHost($preference, $data['host'] ?? null);
        $password = ($data['password'] ?? null) ?: DatabasePassword::generate();

        $engine = $this->manager->engine($database->engine);
        $engine->createUser($data['username'], $host, $password, $database->name);

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
