<?php

namespace App\Services\Server\Databases;

use App\Contracts\ListensRemotely;
use App\Exceptions\Server\Database\RemoteAccessRestartRequiredException;
use App\Models\Database;

/**
 * Makes the engine able to accept a remote user at all, before one is created.
 *
 * PostgreSQL, MySQL and MariaDB all bind to loopback as Ubuntu ships them and
 * cannot be widened without a restart ({@see ListensRemotely}). MySQL and
 * MariaDB were once believed to listen off-box already; on the 26.04 test
 * server MariaDB was on 127.0.0.1:3306, so every remote user the panel had
 * created was unreachable. MongoDB is bound off-box by its installer.
 *
 * **Called before the engine, never after.** A role created first and then
 * refused a restart would be an account that exists, is stored as `remote`, and
 * cannot connect from anywhere — the panel reporting a grant it did not make.
 * Running the check first means a refusal leaves nothing behind.
 */
class RemoteAccessPreparer
{
    public function __construct(private DatabaseManager $manager) {}

    /**
     * @throws RemoteAccessRestartRequiredException
     */
    public function prepare(Database $database, string $preference, bool $consented): void
    {
        if (! in_array($preference, ['remote', 'anywhere'], true)) {
            return;
        }

        $engine = $this->manager->engine($database->engine);

        if (! $engine instanceof ListensRemotely) {
            return;
        }

        // Already bound somewhere other than loopback — by us on a previous
        // remote user, or by an operator who configured it themselves. Either
        // way there is nothing to restart for, and restarting anyway would be
        // an outage bought for no change.
        if ($engine->listensRemotely()) {
            return;
        }

        if (! $consented) {
            throw new RemoteAccessRestartRequiredException(
                (string) config("server.databases.engines.{$database->engine}.label", $database->engine),
            );
        }

        $engine->openRemoteListening();
    }
}
