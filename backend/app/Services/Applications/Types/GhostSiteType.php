<?php

namespace App\Services\Applications\Types;

/**
 * Ghost — publishing, with its own MySQL.
 *
 * The first two-service one-click, and the reason the Docker app types carry a
 * compose template rather than an image: Ghost on SQLite is a development mode
 * upstream does not support in production, so the app is genuinely two
 * containers and a shared secret.
 *
 * No admin fields. Ghost creates its owner account through its own setup screen
 * on first visit, and a site that is reachable and unclaimed is worth saying out
 * loud — the tagline does, the same way Uptime Kuma's does.
 */
class GhostSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'ghost';
    }

    public function category(): string
    {
        return 'cms';
    }

    public function icon(): string
    {
        return 'ghost';
    }

    public function popular(): bool
    {
        return true;
    }

    /** Ghost builds every link and redirect from this. */
    public function urlEnvKey(): ?string
    {
        return 'url';
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.ghost';
    }

    /** Ghost's own default, and what the template publishes. */
    public function containerPort(): int
    {
        return 2368;
    }

    /**
     * Two volumes, and both matter for different reasons: `content` holds
     * uploaded images and themes, which no backup of the database would carry,
     * and `db` holds the posts.
     *
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'content' => '/var/lib/ghost/content',
            'db' => '/var/lib/mysql',
        ];
    }

    /**
     * Two, not one: the application's database user and MySQL's own root
     * account. Sharing a single value between them — which the hand-written
     * file this is derived from did, by connecting Ghost AS root — means the
     * app's credential is also the credential for every database on that
     * server's MySQL.
     *
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['MYSQL_ROOT_PASSWORD', 'GHOST_DB_PASSWORD'];
    }
}
