<?php

namespace App\Services\Server\Docker;

/**
 * Which Docker networks a container may be put on.
 *
 * An existing network is not necessarily one a container may join. Two kinds may not:
 *
 *  - Docker's own. `host` puts the container on the server's network stack, past the loopback
 *    publishing and the firewall; `none` and `bridge` give no name resolution; `ingress` and
 *    `docker_gwbridge` are swarm plumbing; `default` is Compose's word for a project's own.
 *  - Another application's private network, `sv-app-<id>_<key>`: joining it reaches every
 *    container on it, that site's database included.
 *
 * Checked by name, like {@see DockerResources::networks()}: anything in the `sv-app-` namespace
 * is treated as that site's, so a lookalike is refused. Creating such names is refused too
 * ({@see reservedName()}). Pure: no Docker call and no query.
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
     * Refuses the names a container may never join and the `sv-app-` namespace: a pre-made
     * `sv-app-9_default` would be found by Compose, without its labels, when site 9 is created.
     */
    public static function reservedName(string $name): ?string
    {
        if (in_array($name, self::UNJOINABLE, true) || DockerResources::applicationIdFrom($name) !== null) {
            return __('errors/docker.network_name_reserved', ['name' => $name]);
        }

        return null;
    }
}
