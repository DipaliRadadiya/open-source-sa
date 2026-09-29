<?php

namespace App\Services\Applications\Types;

/**
 * Mattermost Team Edition — team chat, with its own Postgres.
 *
 * Three volumes, which is more than any other app here and not padding.
 * Mattermost writes its own configuration: the System Console edits
 * `/mattermost/config/config.json`, so a site without that directory persisted
 * loses every setting an admin changed the moment the container is rebuilt.
 * `data` is uploaded files and `plugins` is anything installed from the
 * marketplace — neither is in the database and neither comes back.
 *
 * The first account created becomes the system admin, so nothing here needs a
 * credential handed to the user.
 */
class MattermostSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'mattermost';
    }

    public function category(): string
    {
        return 'communication';
    }

    public function icon(): string
    {
        return 'mattermost';
    }

    public function popular(): bool
    {
        return true;
    }

    public function composeTemplate(): string
    {
        return 'server.docker.apps.mattermost';
    }

    public function containerPort(): int
    {
        return 8065;
    }

    /**
     * Mattermost builds invitation links, password resets and the mobile app's
     * connection details from this, so a wrong value invites people to a host
     * that does not serve them.
     */
    public function urlEnvKey(): ?string
    {
        return 'MM_SERVICESETTINGS_SITEURL';
    }

    /**
     * A JVM-free Go binary, but it holds the full channel index in memory and
     * upstream's own floor for a small team is 1GB. Measured elsewhere in this
     * feature: an app that will not start inside the 512m default presents as a
     * 502 with nothing about memory anywhere in the panel.
     */
    public function defaultMemoryLimit(): ?string
    {
        return '1g';
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'data' => '/mattermost/data',
            'config' => '/mattermost/config',
            'plugins' => '/mattermost/plugins',
            'db' => '/var/lib/postgresql/data',
        ];
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return ['DATABASE_PASSWORD'];
    }
}
