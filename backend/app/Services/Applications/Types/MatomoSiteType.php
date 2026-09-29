<?php

namespace App\Services\Applications\Types;

/**
 * Matomo — self-hosted web analytics, with its own MariaDB.
 *
 * The Google Analytics replacement people actually mean when they ask for one:
 * the same reports, the data on your own server, and no sampling.
 *
 * **The environment does not configure it — it pre-fills the installer.** Only
 * Matomo's own five-step wizard writes `config.ini.php`, so the first visit still
 * walks through setup with the database page already answered. That is worth
 * saying because it is the difference between this and every other app here: the
 * superuser comes from the wizard, and no card can promise otherwise.
 *
 * `/var/www/html` is a volume because Matomo writes into its own webroot —
 * `config.ini.php`, downloaded plugins, the GeoIP database. Without it a rebuild
 * loses the configuration and re-runs the installer against a populated database.
 */
class MatomoSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'matomo';
    }

    public function category(): string
    {
        return 'analytics';
    }

    public function icon(): string
    {
        return 'matomo';
    }

    public function popular(): bool
    {
        return true;
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.matomo';
    }

    /** Apache inside the image. */
    public function containerPort(): int
    {
        return 80;
    }

    /**
     * Matomo asks for its URL during setup and stores it in the database, so
     * there is nothing to pass — and passing one would be a second source for a
     * value the app owns. {@see AbstractDockerAppType::urlEnvKey()}
     *
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'app' => '/var/www/html',
            'db' => '/var/lib/mysql',
        ];
    }

    /**
     * Two: the application's database user and MariaDB's own root account. The
     * image needs root to create the schema; Matomo itself must never have it.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['DATABASE_PASSWORD', 'MARIADB_ROOT_PASSWORD'];
    }
}
