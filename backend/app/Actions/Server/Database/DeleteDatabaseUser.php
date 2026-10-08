<?php

namespace App\Actions\Server\Database;

use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseFirewall;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Validation\ValidationException;

class DeleteDatabaseUser
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
        private DatabaseFirewall $firewall,
    ) {}

    public function execute(DatabaseUser $user): void
    {
        // FS-C14(a): the panel's own account (and the engines' built-in
        // ones). Removing or renaming it breaks every database operation
        // with no way back through the panel — the screen only hid it.
        if ($this->manager->isSystemUser($user->username)) {
            throw ValidationException::withMessages([
                'username' => [__('errors/database.panel_user_protected', ['username' => $user->username])],
            ]);
        }

        $database = $user->database;
        $engine = $this->manager->engine($database->engine);

        $engine->dropUser($user->username, $user->host, $database->name);

        $this->activityLogger->log('database.user_deleted', null, [
            'username' => $user->username,
            'database' => $database->name,
        ]);

        $user->delete();

        $this->firewall->release($database->engine, $user->connection_preference, $user->host);
    }
}
