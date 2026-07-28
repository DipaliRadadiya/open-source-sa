<?php

namespace App\Actions\Server\Database;

use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;

class ChangeDatabaseUserPassword
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Ordered + isolated: (1) engine ALTER USER must succeed; (2) update the
     * stored credential. The optional app `.env` resync is P3 (with the
     * Application feature) and is best-effort — it never breaks this op.
     */
    public function execute(DatabaseUser $user, string $password): DatabaseUser
    {
        $database = $user->database;
        $engine = $this->manager->engine($database->engine);

        $engine->setPassword($user->username, $user->host, $password, $database->name);

        $user->update(['password' => $password]);

        $this->activityLogger->log('database.password_reset', $user, [
            'username' => $user->username,
            'database' => $database->name,
        ]);

        return $user;
    }
}
