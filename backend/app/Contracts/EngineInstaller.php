<?php

namespace App\Contracts;

use App\Exceptions\Server\Database\EngineInstallException;

/**
 * Puts a database engine on the server and hands the panel a credential for it.
 *
 * Deliberately separate from {@see DatabaseEngine}: that contract is twenty
 * methods about *operating* an engine that already exists — listing databases,
 * creating users, dumping. Creating the engine in the first place is a different
 * job with a different lifecycle, and folding it in would force every operating
 * method to exist on something that has not been installed yet.
 *
 * Implementations must not touch the engine's root account. On MariaDB 10.4+ and
 * MySQL 8 on Ubuntu, root authenticates over the unix socket and has password
 * login disabled outright — so giving it a password is not reading a secret, it
 * is creating one, and it makes root usable over TCP. The panel gets its own
 * account instead. See memory/database-engine-install-design.md.
 */
interface EngineInstaller
{
    /** mysql | mariadb | mongodb */
    public function engine(): string;

    /**
     * Whether the engine's server package is present on this box.
     *
     * Detected, never remembered — a package removed outside the panel has to
     * show up as absent, and a server migrated in from elsewhere has to show up
     * as present without anyone clicking install.
     */
    public function installed(): bool;

    /**
     * Install the engine, provision the panel's own account, and store the
     * credential.
     *
     * Idempotent: running it again on a server that already has the engine
     * rotates the panel's password rather than installing anything or creating a
     * second account.
     *
     * @throws EngineInstallException
     */
    /**
     * @param  callable(string): void|null  $onStep
     * @param  callable(string): void|null  $onOutput
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null): void;
}
