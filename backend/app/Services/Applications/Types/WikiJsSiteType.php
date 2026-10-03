<?php

namespace App\Services\Applications\Types;

/**
 * Wiki.js — documentation, with its own Postgres.
 *
 * One volume, and only for the database: Wiki.js keeps pages, uploads and its own
 * configuration in Postgres, so there is no application directory worth
 * persisting. That is unusual enough among these apps to be worth stating rather
 * than looking like a forgotten mount.
 */
class WikiJsSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'wikijs';
    }

    public function category(): string
    {
        return 'cms';
    }

    public function icon(): string
    {
        return 'wikijs';
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.wikijs';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return ['db' => '/var/lib/postgresql/data'];
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return ['DATABASE_PASSWORD'];
    }
}
