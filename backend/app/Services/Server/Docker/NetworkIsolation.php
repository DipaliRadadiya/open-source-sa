<?php

namespace App\Services\Server\Docker;

/**
 * Which Docker networks a container may be put on (RC-28).
 *
 * Every place that lets a user name a network asked only whether it exists, and
 * a network that exists is not one a container may join. Two kinds may not:
 *
 *  - Docker's own. `host` puts the container on the server's network stack,
 *    past the loopback publishing this panel relies on and past the firewall;
 *    `none` and `bridge` give no name resolution, so the site cannot reach its
 *    database; `ingress` and `docker_gwbridge` are swarm plumbing. `default` is
 *    not a network at all but the word Compose uses for a project's own, and a
 *    file naming it externally means something nobody intended.
 *  - Another application's private network, `sv-app-<id>_<key>` — the one
 *    Compose made for that site's project. Joining it reaches every container
 *    on it, that site's database included. Two sites that should talk share a
 *    network made for the purpose, on the Docker screen.
 *
 * By name, deliberately, and the same reading {@see DockerResources::networks()}
 * uses to show the owning site: a name in the `sv-app-` namespace is treated as
 * that site's whoever made it, so a network created to look like one is refused
 * rather than trusted. The panel refuses to create such names for the same
 * reason ({@see reservedName()}).
 *
 * Pure: no Docker call and no query. A refusal never reaches `docker`.
 */
class NetworkIsolation
{
    /**
     * Never a container's network, whoever asks.
     *
     * @var list<string>
     */
    public const UNJOINABLE = ['bridge', 'host', 'none', 'default', 'ingress', 'docker_gwbridge'];

    /**
     * Why a container may not join this network, or null when it may.
     *
     * @param  int|null  $applicationId  the site joining it — its own networks are
     *                                   allowed. Null for something that is not a
     *                                   site yet (or not a site at all): then every
     *                                   site's private network is someone else's.
     */
    public static function refusal(string $name, ?int $applicationId = null): ?string
    {
        if (in_array($name, self::UNJOINABLE, true)) {
            return __('errors/docker.network_docker_own', ['name' => $name]);
        }

        $owner = DockerResources::applicationIdFrom($name);

        if ($owner !== null && $owner !== $applicationId) {
            return __('errors/docker.network_other_application', ['name' => $name]);
        }

        return null;
    }

    /**
     * May the panel CREATE a network with this name?
     *
     * Not the built-ins Docker already has — those are refused as taken — but
     * the ones a container may never join, and the `sv-app-` namespace: a
     * network made as `sv-app-9_default` before site 9 exists is one Compose
     * finds already there, without its labels, when that site is created.
     */
    public static function reservedName(string $name): ?string
    {
        if (in_array($name, self::UNJOINABLE, true) || DockerResources::applicationIdFrom($name) !== null) {
            return __('errors/docker.network_name_reserved', ['name' => $name]);
        }

        return null;
    }
}
