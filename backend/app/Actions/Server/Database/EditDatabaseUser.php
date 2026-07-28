<?php

namespace App\Actions\Server\Database;

use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseFirewall;
use App\Services\Server\Databases\DatabaseManager;

/**
 * Edit an existing DB user's credentials: username, connection_preference/host
 * (remote toggle), and/or password — in one call. SQL uses RENAME USER
 * (grants preserved); Mongo (no rename) drops + recreates with the password.
 */
class EditDatabaseUser
{
    public function __construct(
        private DatabaseManager $manager,
        private DatabaseFirewall $firewall,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{username?: string, connection_preference?: string, host?: ?string, password?: ?string}  $data
     */
    public function execute(DatabaseUser $user, array $data): DatabaseUser
    {
        $database = $user->database;
        $engine = $this->manager->engine($database->engine);

        $newUsername = $data['username'] ?? $user->username;
        $newPreference = $data['connection_preference'] ?? $user->connection_preference;
        $newHost = $this->resolveHost($newPreference, $data['host'] ?? null, $user);
        $newPassword = ($data['password'] ?? null) ?: $user->password;

        $renamed = $newUsername !== $user->username || $newHost !== $user->host;
        $passwordChanged = ! empty($data['password']);

        if ($renamed) {
            // SQL RENAME preserves the password; Mongo recreates with $newPassword.
            $engine->renameUser($user->username, $user->host, $newUsername, $newHost, $newPassword, $database->name);
            if ($passwordChanged && $this->manager->driver($database->engine) !== 'mongo') {
                $engine->setPassword($newUsername, $newHost, $newPassword, $database->name);
            }
        } elseif ($passwordChanged) {
            $engine->setPassword($newUsername, $newHost, $newPassword, $database->name);
        }

        $this->firewall->sync($database->engine, $newPreference, $newHost);

        $user->update([
            'username' => $newUsername,
            'host' => $newHost,
            'connection_preference' => $newPreference,
            'password' => $newPassword,
        ]);

        $this->activityLogger->log('database.user_updated', $user, [
            'username' => $newUsername,
            'database' => $database->name,
        ]);

        return $user;
    }

    private function resolveHost(string $preference, ?string $host, DatabaseUser $user): string
    {
        return match ($preference) {
            'anywhere' => '%',
            'remote' => (string) ($host ?? ($user->connection_preference === 'remote' ? $user->host : $host)),
            default => 'localhost',
        };
    }
}
