<?php

namespace App\Services\Server\Databases;

use App\Exceptions\Server\Database\RemoteAccessRestartRequiredException;
use App\Models\Database;

/**
 * Makes the cluster able to accept a remote user at all, before one is created.
 *
 * Only PostgreSQL needs this. MySQL and MongoDB are already listening off-box
 * by the time the panel has finished installing them, so `CREATE USER` is the
 * whole grant; PostgreSQL binds to loopback by default and cannot be widened
 * without a restart.
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

        // Typed on the concrete engine rather than a new contract method:
        // this is one engine's operational quirk, and widening the interface
        // would make every other engine answer a question it does not have.
        if (! $engine instanceof PgsqlEngine) {
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
            throw new RemoteAccessRestartRequiredException;
        }

        $engine->openRemoteListening();
    }
}
