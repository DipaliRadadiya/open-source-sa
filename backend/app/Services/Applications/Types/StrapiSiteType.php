<?php

namespace App\Services\Applications\Types;

/**
 * Strapi — headless CMS, with its own Postgres.
 *
 * Four generated secrets rather than one, and that is the interesting part of
 * this app: Strapi signs sessions, user tokens and admin tokens with separate
 * keys and derives API tokens from a salt. It starts without them in development
 * and refuses to in production, so they are not optional — and a value shipped
 * in a template would be shared by every panel-installed Strapi in the world.
 *
 * No admin fields. Strapi creates the first administrator through its own
 * `/admin` signup on first visit, and until somebody does, anyone can — which
 * the tagline says rather than leaving the site looking finished.
 */
class StrapiSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'strapi';
    }

    public function category(): string
    {
        return 'cms';
    }

    public function icon(): string
    {
        return 'strapi';
    }

    public function popular(): bool
    {
        return true;
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.strapi';
    }

    public function containerPort(): int
    {
        return 1337;
    }

    /**
     * Uploads and the database. Not the code: that is in the image, which is the
     * difference between this and the host-installed one-clicks.
     *
     * @return array<string, string>
     */
    public function volumeRoles(): array
    {
        return [
            'uploads' => '/opt/app/public/uploads',
            'db' => '/var/lib/postgresql/data',
        ];
    }

    /**
     * @return list<string>
     */
    public function generatedSecrets(): array
    {
        return ['DATABASE_PASSWORD', 'APP_KEYS', 'JWT_SECRET', 'ADMIN_JWT_SECRET', 'API_TOKEN_SALT'];
    }
}
