<?php

namespace App\Services\Server\Databases\Installers;

/**
 * MySQL, for people who specifically want it — most often because an application
 * they are migrating depends on a MySQL-only feature.
 */
class MySqlInstaller extends AbstractSqlEngineInstaller
{
    public function engine(): string
    {
        return 'mysql';
    }

    protected function packages(): array
    {
        return ['mysql-server'];
    }

    protected function service(): string
    {
        return 'mysql';
    }

    protected function conflictsWith(): string
    {
        return 'mariadb';
    }
}
