<?php

namespace App\Services\Applications\Types;

/**
 * Homepage — a start page for the services on a server.
 *
 * Like Glance, configuration is files in `/app/config` rather than a database.
 *
 * It can read Docker to discover services, which needs the Docker socket — and
 * the panel refuses that mount for the reason it refuses it everywhere: read-only
 * socket access is still control of the daemon. Homepage works without it; the
 * service widgets are configured by hand instead.
 */
class HomepageSiteType extends AbstractDockerAppType
{
    public function name(): string
    {
        return 'homepage';
    }

    public function category(): string
    {
        return 'monitoring';
    }

    public function icon(): string
    {
        return 'homepage';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    /** @return array<string, string> */
    public function volumeRoles(): array
    {
        return [
            'config' => '/app/config',
        ];
    }
}
