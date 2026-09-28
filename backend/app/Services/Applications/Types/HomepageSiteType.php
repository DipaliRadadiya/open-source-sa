<?php

namespace App\Services\Applications\Types;

use App\Models\Application;

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

    /**
     * Homepage refuses a request whose Host header it was not told about:
     *
     *   error: Host validation failed for: homepage.example.com.
     *   Hint: Set the HOMEPAGE_ALLOWED_HOSTS environment variable
     *
     * It answers with a 400 rather than failing to start, so the container looks
     * healthy and the site is unusable — which is why this was only found by
     * opening it. Every site the panel creates is reached through nginx on its own
     * domain, so the domain is exactly what belongs here.
     *
     * @return array<string, string>
     */
    public function environment(Application $application): array
    {
        return ['HOMEPAGE_ALLOWED_HOSTS' => (string) $application->domain];
    }
}
