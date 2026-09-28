<?php

namespace App\Services\Applications\Types;

/**
 * NocoDB — a database with a spreadsheet interface, and its own Postgres.
 *
 * The first person to open the site creates the super admin, which is why this
 * needs no credential handed to the user — the same shape as Ghost, and the
 * reason Directus is not here despite being the closer Strapi substitute:
 * Directus has no self-signup at all and creates its first admin only from
 * environment variables.
 */
class NocoDbSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'nocodb';
    }

    public function category(): string
    {
        return 'database';
    }

    public function icon(): string
    {
        return 'nocodb';
    }

    /** Used for invitation links and anything NocoDB emails. */
    public function urlEnvKey(): ?string
    {
        return 'NC_PUBLIC_URL';
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.nocodb';
    }

    public function containerPort(): int
    {
        return 8080;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'data' => '/usr/app/data',
            'db' => '/var/lib/postgresql/data',
        ];
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return ['DATABASE_PASSWORD'];
    }
}
