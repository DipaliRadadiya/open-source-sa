<?php

namespace App\Services\Applications\Types;

/**
 * Metabase — dashboards and questions over your data, with its own Postgres.
 *
 * Postgres rather than the embedded H2 Metabase falls back to. H2 is a single
 * file upstream documents as unsuitable for production and which cannot be
 * copied safely while the app is running — a one-click that quietly hands
 * somebody that is a one-click that loses their dashboards.
 *
 * One volume, for the database. Metabase keeps everything in it.
 */
class MetabaseSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'metabase';
    }

    public function category(): string
    {
        return 'analytics';
    }

    public function icon(): string
    {
        return 'metabase';
    }

    /**
     * Metabase is a JVM application and sizes its heap at a quarter of the
     * container limit. At the panel's 512m default it reported 123.8 MB available
     * and wrote a JVM crash log; upstream documents 1GB as the minimum and 2GB as
     * recommended. Measured on the test box, not guessed.
     */
    public function defaultMemoryLimit(): ?string
    {
        return '2g';
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.metabase';
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
