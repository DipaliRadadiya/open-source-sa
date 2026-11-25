<?php

namespace App\Contracts;

use App\Exceptions\Server\Database\EngineInstallException;

/**
 * Puts a database engine on the server and hands the panel a credential for it.
 *
 * Separate from {@see DatabaseEngine}, which operates an engine that already exists (databases,
 * users, dumps); installing has a different lifecycle.
 *
 * Implementations must not touch the engine's root account. On MariaDB 10.4+ and MySQL 8 on
 * Ubuntu, root uses the unix socket with password login disabled; giving it a password would make
 * root usable over TCP. The panel gets its own account instead.
 */
interface EngineInstaller
{
    /** mysql | mariadb | mongodb */
    public function engine(): string;

    /**
     * Whether the engine's server package is present on this box.
     *
     * Detected, never remembered: a package removed or added outside the panel must show correctly.
     */
    public function installed(): bool;

    /**
     * Install the engine, provision the panel's own account, and store the credential.
     *
     * Idempotent: on a server that already has the engine it rotates the panel's password instead.
     *
     * @throws EngineInstallException
     */
    /**
     * @param  callable(string): void|null  $onStep
     * @param  callable(string): void|null  $onOutput
     */
    /**
     * @param  bool|null  $wasAbsent  whether the engine was absent when the install was
     *                                *requested*, or null when not recorded. Installers that write
     *                                configuration must prefer this over asking the box: on a retry
     *                                the package is already there and would look like somebody else's.
     */
    public function install(?callable $onStep = null, ?callable $onOutput = null, ?bool $wasAbsent = null): void;
}
