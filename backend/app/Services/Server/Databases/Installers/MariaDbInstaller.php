<?php

namespace App\Services\Server\Databases\Installers;

/**
 * MariaDB, which is the panel's default choice: it is what Ubuntu packages
 * directly, so there is no third-party repository to add and no version that
 * falls out of support with the release.
 */
class MariaDbInstaller extends AbstractSqlEngineInstaller
{
    public function engine(): string
    {
        return 'mariadb';
    }

    protected function packages(): array
    {
        return ['mariadb-server'];
    }

    /**
     * Ubuntu ships both `mariadb` and `mysql` as unit names for this package —
     * `mysql` is an alias kept for compatibility. The real one is used, so a
     * `systemctl enable` cannot land on the alias and confuse later status reads.
     */
    protected function service(): string
    {
        return 'mariadb';
    }

    protected function conflictsWith(): string
    {
        return 'mysql';
    }
}
