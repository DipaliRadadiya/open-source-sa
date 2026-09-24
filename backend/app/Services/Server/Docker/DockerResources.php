<?php

namespace App\Services\Server\Docker;

use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Docker's networks and volumes, as the panel presents them.
 *
 * Server-level, not per-application, because that is what they are: a network
 * and a volume are shared objects that outlive any one container. A per-app
 * screen would be a view onto a server-wide list pretending to be owned.
 *
 * Every read is a `--format {{json .}}` call. Docker's table output is for
 * humans and changes between versions; the JSON form is stable and needs no
 * column parsing.
 */
class DockerResources
{
    /**
     * Networks Docker creates itself and nobody may remove.
     *
     * Removing one breaks every container on the box, and Docker recreates
     * them on restart anyway — so a delete button here would be a control that
     * either fails or does damage. They are still listed, because hiding them
     * makes the panel disagree with `docker network ls`, and flagged so the UI
     * can show them without an action.
     *
     * @var list<string>
     */
    public const BUILT_IN_NETWORKS = ['bridge', 'host', 'none'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function networks(): array
    {
        $rows = $this->jsonLines(['docker', 'network', 'ls', '--format', '{{json .}}'], 'docker_network_ls');

        return array_map(function (array $row): array {
            $name = (string) ($row['Name'] ?? '');

            return [
                'id' => (string) ($row['ID'] ?? ''),
                'name' => $name,
                'driver' => (string) ($row['Driver'] ?? ''),
                'scope' => (string) ($row['Scope'] ?? ''),
                'internal' => ($row['Internal'] ?? 'false') === 'true',
                'built_in' => in_array($name, self::BUILT_IN_NETWORKS, true),
                // Compose names a project's network `<project>_default`, and
                // the panel's projects are `sv-app-<id>`. Saying so lets the
                // UI show which application owns it, rather than presenting a
                // machine-generated name as though a human chose it.
                'application_id' => $this->applicationIdFrom($name),
                'containers' => $this->attachments($name),
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function volumes(): array
    {
        // `system df -v`, not `volume ls`: the size column is the one people
        // actually want and `volume ls` reports it as "N/A". `Links` arrives
        // with it — how many containers use the volume — which makes "in use"
        // answerable without a second call per volume.
        $result = $this->serverOps->run(
            ['docker', 'system', 'df', '-v', '--format', '{{json .Volumes}}'],
            ['feature' => 'docker', 'op' => 'docker_volume_df'],
            timeout: 60,
        );

        if (! $result->answered) {
            return [];
        }

        $rows = json_decode(trim($result->output()), true);

        if (! is_array($rows)) {
            return [];
        }

        return array_map(function (array $row): array {
            $links = (int) ($row['Links'] ?? 0);

            return [
                'name' => (string) ($row['Name'] ?? ''),
                'driver' => (string) ($row['Driver'] ?? ''),
                'mountpoint' => (string) ($row['Mountpoint'] ?? ''),
                // As Docker renders it — "5.243MB". Not re-parsed into bytes:
                // that would be the panel guessing at a unit, and the only use
                // for the value is to be read.
                'size' => (string) ($row['Size'] ?? ''),
                'containers' => $links,
                'in_use' => $links > 0,
                // A volume with no container is not necessarily rubbish — it
                // may belong to a stopped application — so it is labelled
                // rather than swept up.
                'dangling' => $links === 0,
                'application_id' => $this->applicationIdFrom((string) ($row['Name'] ?? '')),
            ];
        }, $rows);
    }

    /**
     * Create a user-defined bridge network.
     *
     * Bridge, and not a choice: `overlay` needs swarm, `macvlan` needs a
     * parent interface and hands the container an address on the host's LAN,
     * and `host` is the thing the whole design refuses. Offering a driver
     * dropdown would be offering three ways to break the box and one that
     * works.
     *
     * The reason user-defined networks matter at all: only they give DNS
     * resolution by container name, which is how an application reaches its
     * database container. Docker's own `bridge` does not.
     */
    public function createNetwork(string $name): ServerOpsResult
    {
        return $this->serverOps->run(
            ['docker', 'network', 'create', '--driver', 'bridge', $name],
            ['feature' => 'docker', 'op' => 'docker_network_create'],
            timeout: 30,
        );
    }

    public function removeNetwork(string $name): ServerOpsResult
    {
        return $this->serverOps->run(
            ['docker', 'network', 'rm', $name],
            ['feature' => 'docker', 'op' => 'docker_network_remove'],
            timeout: 30,
        );
    }

    public function createVolume(string $name): ServerOpsResult
    {
        return $this->serverOps->run(
            ['docker', 'volume', 'create', $name],
            ['feature' => 'docker', 'op' => 'docker_volume_create'],
            timeout: 30,
        );
    }

    /**
     * Remove a volume — and never with `--force`.
     *
     * `docker volume rm -f` removes a volume that is still attached to a
     * container, which is how somebody deletes a database while it is
     * running. The panel checks first and refuses; without the check, Docker
     * would happily do it.
     */
    public function removeVolume(string $name): ServerOpsResult
    {
        return $this->serverOps->run(
            ['docker', 'volume', 'rm', $name],
            ['feature' => 'docker', 'op' => 'docker_volume_remove'],
            timeout: 30,
        );
    }

    /**
     * A name Docker will accept, and that cannot be an argument.
     *
     * The value reaches a command line. Docker's own rule is
     * `[a-zA-Z0-9][a-zA-Z0-9_.-]*`, which already excludes whitespace and
     * every shell metacharacter — and, importantly, a leading `-`, so a name
     * cannot arrive as a flag.
     */
    public static function validName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/', $name) === 1;
    }

    /**
     * The containers attached to a network, by name.
     *
     * Why a delete has to consult this: Docker's own error for a network in
     * use names neither the network nor what is on it, so passing it through
     * leaves the user to run `docker` themselves to find out.
     *
     * @return list<string>
     */
    private function attachments(string $network): array
    {
        $result = $this->serverOps->run(
            ['docker', 'network', 'inspect', $network, '--format', '{{json .Containers}}'],
            ['feature' => 'docker', 'op' => 'docker_network_inspect'],
            timeout: 30,
        );

        if (! $result->answered) {
            return [];
        }

        $decoded = json_decode(trim($result->output()), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            fn ($container) => (string) ($container['Name'] ?? ''),
            $decoded,
        ));
    }

    /**
     * The application a compose-created name belongs to, or null.
     *
     * `sv-app-6_default` belongs to application 6.
     * {@see ContainerSupervisor::project()}
     * is where that prefix is decided.
     */
    private function applicationIdFrom(string $name): ?int
    {
        return preg_match('/^sv-app-(\d+)[_-]/', $name, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /**
     * @param  array<int, string>  $command
     * @return list<array<string, mixed>>
     */
    private function jsonLines(array $command, string $op): array
    {
        $result = $this->serverOps->run(
            $command,
            ['feature' => 'docker', 'op' => $op],
            timeout: 30,
        );

        if (! $result->answered) {
            return [];
        }

        $rows = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
