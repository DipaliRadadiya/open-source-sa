<?php

namespace App\Services\Server\Docker;

use App\Models\Application;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Collection;

/**
 * Docker's networks and volumes, as the panel presents them.
 *
 * Server-level, not per-application: networks and volumes are shared objects that outlive any
 * one container. Every read is a `--format {{json .}}` call, because Docker's table output
 * changes between versions.
 */
class DockerResources
{
    /**
     * Networks Docker creates itself and nobody may remove.
     *
     * Removing one breaks every container on the box. They are still listed (to match
     * `docker network ls`) and flagged so the UI shows them without an action.
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

        // `network inspect` knows which containers are on a network but not their ports; `docker ps`
        // knows the ports but not the networks. One call each, joined on the name.
        $ports = $this->containerPorts();

        // Which sites NAME a network, not which containers are on it now: a stopped site's compose
        // file still says `external: true`, so deleting the network would break its next start.
        $sites = Application::query()
            ->whereNotNull('docker_network')
            ->get(['id', 'name', 'docker_network'])
            ->groupBy('docker_network');

        // `use ($ports)` is load-bearing: without it `$ports` is undefined in the closure and the
        // `??` below silently reports every container as internal-only.
        return array_map(function (array $row) use ($ports, $sites): array {
            $name = (string) ($row['Name'] ?? '');

            return [
                'id' => (string) ($row['ID'] ?? ''),
                'name' => $name,
                'driver' => (string) ($row['Driver'] ?? ''),
                'scope' => (string) ($row['Scope'] ?? ''),
                'internal' => ($row['Internal'] ?? 'false') === 'true',
                'built_in' => in_array($name, self::BUILT_IN_NETWORKS, true),
                // Compose names a project's network `<project>_default` and the panel's projects are
                // `sv-app-<id>`, so the UI can show which application owns it.
                'application_id' => $this->applicationIdFrom($name),
                // The sites that joined it on purpose, so a refusal can name them.
                // `->get($name, collect())`, not `?? collect()`: the `??` form also swallows an
                // undefined variable (see `use ($ports)` above).
                'sites' => $sites->get($name, collect())
                    ->map(fn (Application $application): array => [
                        'id' => $application->id,
                        'name' => $application->name,
                    ])
                    ->values()
                    ->all(),
                'containers' => array_map(
                    fn (string $container): array => [
                        'name' => $container,
                        'ports' => $ports[$container]['ports'] ?? [],
                        // Whether anything on the host can reach it: `127.0.0.1:2368->2368/tcp` is
                        // published, a bare `3306/tcp` is only exposed to its own network.
                        'published' => $ports[$container]['published'] ?? false,
                    ],
                    $this->attachments($name),
                ),
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function volumes(): array
    {
        // `system df -v`, not `volume ls`: it reports the size (`volume ls` says "N/A") and `Links`
        // (how many containers use the volume), which answers "in use" without a call per volume.
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

        // Asked once for every volume, not once per row.
        $users = $this->volumeContainers();

        // Which sites MOUNT each volume, which `Links` cannot answer: a stopped site has no container,
        // so `Links` is 0 while the volume still holds its data. Deleting it would lose that data.
        $sites = Application::query()
            ->whereNotNull('volume_mounts')
            ->get(['id', 'name', 'volume_mounts'])
            ->flatMap(fn (Application $application): array => collect((array) $application->volume_mounts)
                ->filter(fn ($mount): bool => is_array($mount) && ($mount['volume'] ?? '') !== '')
                ->map(fn (array $mount): array => [
                    'volume' => (string) $mount['volume'],
                    'site' => [
                        'id' => $application->id,
                        'name' => $application->name,
                        // WHERE it is mounted (`/var/lib/mysql` says "database"). One row per mount,
                        // because a volume can be mounted at two paths.
                        'path' => (string) ($mount['path'] ?? ''),
                    ],
                ])
                ->all())
            ->groupBy('volume')
            ->map(fn (Collection $rows): array => $rows->pluck('site')->values()->all());

        return array_map(function (array $row) use ($users, $sites): array {
            $links = (int) ($row['Links'] ?? 0);
            $name = (string) ($row['Name'] ?? '');

            return [
                'name' => $name,
                'driver' => (string) ($row['Driver'] ?? ''),
                'mountpoint' => (string) ($row['Mountpoint'] ?? ''),
                // As Docker renders it ("5.243MB"); not re-parsed into bytes.
                'size' => (string) ($row['Size'] ?? ''),
                'containers' => $links,
                // Named, because a count does not say which one to stop.
                // `->get()` with a default, never `?? []` (see `networks()`).
                'container_names' => $users->get($name, []),
                // The sites configured to mount it, running or not.
                'sites' => $sites->get($name, []),
                // Deliberately `$links`, not `count($container_names)`: this is what the delete guard
                // reads, and a volume with users must be refused even if `inspect` could not name them.
                'in_use' => $links > 0,
                // Not necessarily rubbish: it may belong to a stopped application, so it is labelled.
                'dangling' => $links === 0,
                'application_id' => $this->applicationIdFrom($name),
            ];
        }, $rows);
    }

    /**
     * The containers using each volume, keyed by volume name.
     *
     * **Not `docker ps`:** `docker ps --format` TRUNCATES the mount list, so joining on it matches
     * nothing or the wrong volume. `container inspect` returns the full name and a `Type`, which
     * excludes bind mounts. `-a`, so stopped containers count, as `Links` does.
     *
     * @return Collection<string, list<string>>
     */
    private function volumeContainers(): Collection
    {
        $ids = $this->serverOps->run(
            ['docker', 'ps', '-aq'],
            ['feature' => 'docker', 'op' => 'docker_ps_ids'],
            timeout: 30,
        );

        if (! $ids->answered) {
            return collect();
        }

        $list = array_values(array_filter(array_map('trim', explode("\n", $ids->output()))));

        // `docker container inspect` with no arguments is an error, not an empty answer.
        if ($list === []) {
            return collect();
        }

        $map = [];

        foreach ($this->jsonLines(
            ['docker', 'container', 'inspect', '--format', '{{json .}}', ...$list],
            'docker_container_inspect',
        ) as $row) {
            $name = ltrim((string) ($row['Name'] ?? ''), '/');

            if ($name === '') {
                continue;
            }

            foreach ((array) ($row['Mounts'] ?? []) as $mount) {
                if (! is_array($mount) || ($mount['Type'] ?? null) !== 'volume') {
                    continue;
                }

                $volume = (string) ($mount['Name'] ?? '');

                if ($volume !== '' && ! in_array($name, $map[$volume] ?? [], true)) {
                    $map[$volume][] = $name;
                }
            }
        }

        return collect($map);
    }

    /**
     * Published ports per running container, keyed by name.
     *
     * Docker renders `127.0.0.1:2368->2368/tcp` for a port published to the host and a bare
     * `3306/tcp` for one merely exposed to other containers. **The arrow is the difference.**
     *
     * @return array<string, array{ports: list<string>, published: bool}>
     */
    private function containerPorts(): array
    {
        $rows = $this->jsonLines(['docker', 'ps', '--format', '{{json .}}'], 'docker_ps_ports');

        $map = [];

        foreach ($rows as $row) {
            $name = (string) ($row['Names'] ?? '');

            if ($name === '') {
                continue;
            }

            $ports = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($row['Ports'] ?? '')),
            )));

            $map[$name] = [
                'ports' => $ports,
                'published' => (bool) array_filter($ports, fn (string $port) => str_contains($port, '->')),
            ];
        }

        return $map;
    }

    /**
     * Make sure the objects a site's compose file names actually exist.
     *
     * Called before `compose up`, because `external: true` means Compose will not create them:
     * the site may have been created with "make a new network/volume", or someone removed the
     * object on the box. Creates rather than fails. Checks first, since `network create` errors
     * on an existing name and `volume create` silently returns the existing one.
     *
     * @return list<string> what it had to create, for the provisioning log
     */
    public function ensureFor(Application $application): array
    {
        $created = [];

        $network = (string) ($application->docker_network ?? '');

        if ($network !== '' && ! collect($this->networks())->contains(fn (array $row): bool => $row['name'] === $network)) {
            if (! $this->createNetwork($network)->failed()) {
                $created[] = "network:{$network}";
            }
        }

        $wanted = collect((array) ($application->volume_mounts ?? []))
            ->pluck('volume')
            ->filter()
            ->unique();

        if ($wanted->isEmpty()) {
            return $created;
        }

        // One listing for every volume, rather than one per mount.
        $existing = collect($this->volumes())->pluck('name')->all();

        foreach ($wanted as $volume) {
            if (! in_array($volume, $existing, true) && ! $this->createVolume($volume)->failed()) {
                $created[] = "volume:{$volume}";
            }
        }

        return $created;
    }

    /**
     * Create a user-defined bridge network.
     *
     * Always bridge: `overlay` needs swarm, `macvlan` puts the container on the host's LAN, and
     * `host` is what the design refuses. User-defined networks give DNS by container name, which
     * is how an application reaches its database container (Docker's own `bridge` does not).
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

    /**
     * Was this network created by Docker Compose as a project's own default
     * network — and if so, for which project? Null when it was not, or when
     * Docker could not be asked.
     *
     * The name alone is a guess (anybody can create `sv-app-6_default`); Compose's project and
     * network labels make it a fact. A network made for a file that names none is keyed `default`.
     *
     * @return array{project: string, network: string}|null
     */
    public function composeNetwork(string $name): ?array
    {
        $result = $this->serverOps->run(
            ['docker', 'network', 'inspect', '--format',
                '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.network"}}', $name],
            ['feature' => 'docker', 'op' => 'docker_network_labels'],
            timeout: 30,
        );

        if ($result->failed()) {
            return null;
        }

        [$project, $network] = array_pad(explode('|', trim($result->output()), 2), 2, '');

        // A missing label prints as `<no value>` on some Docker versions and as nothing on others.
        $project = $project === '<no value>' ? '' : trim($project);
        $network = $network === '<no value>' ? '' : trim($network);

        return $project === '' || $network === '' ? null : ['project' => $project, 'network' => $network];
    }

    /**
     * The host ports a compose project's containers publish right now.
     *
     * `docker ps` prints `127.0.0.1:20001->2368/tcp`, a range as `127.0.0.1:8000-8010->8000-8010/tcp`
     * and an IPv6 copy as `[::]:8080->80/tcp`. Only the host side of each arrow is read.
     * Nothing — not an error — when Docker cannot be asked.
     *
     * @return list<int>
     */
    public function publishedHostPorts(string $project): array
    {
        $result = $this->serverOps->run(
            ['docker', 'ps', '--filter', "label=com.docker.compose.project={$project}", '--format', '{{.Ports}}'],
            ['feature' => 'docker', 'op' => 'docker_project_ports'],
            timeout: 30,
        );

        if ($result->failed()) {
            return [];
        }

        preg_match_all('/:(\d+)(?:-(\d+))?->/', $result->output(), $matches, PREG_SET_ORDER);

        $ports = [];

        foreach ($matches as $match) {
            $low = (int) $match[1];
            $high = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : $low;

            // A range is a handful of ports; anything absurd is not a range.
            if ($high < $low || $high - $low > 1000) {
                continue;
            }

            array_push($ports, ...range($low, $high));
        }

        return array_values(array_unique($ports));
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
     * `docker volume rm -f` removes a volume still attached to a container. The panel checks first and refuses.
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
     * Every container on the server, running or stopped, with the image it
     * was created from and its Compose project. Empty when Docker did not
     * answer — which callers must treat as "unknown", never as "none".
     *
     * @return list<array{name: string, image: string, project: ?string}>
     */
    public function containers(): array
    {
        return array_values(array_map(function (array $row): array {
            $project = null;

            foreach (explode(',', (string) ($row['Labels'] ?? '')) as $label) {
                if (str_starts_with($label, 'com.docker.compose.project=')) {
                    $project = substr($label, strlen('com.docker.compose.project='));
                }
            }

            return ['name' => (string) ($row['Names'] ?? ''), 'image' => (string) ($row['Image'] ?? ''), 'project' => $project];
        }, $this->jsonLines(['docker', 'ps', '-a', '--no-trunc', '--format', '{{json .}}'], 'docker_container_ls')));
    }

    /**
     * Remove an image — never with `--force`, so Docker itself refuses one a container still uses.
     */
    public function removeImage(string $image): ServerOpsResult
    {
        return $this->serverOps->run(
            ['docker', 'image', 'rm', '--', $image],
            ['feature' => 'docker', 'op' => 'docker_image_remove'],
            timeout: 60,
        );
    }

    /**
     * A name Docker will accept, and that cannot be an argument.
     *
     * The value reaches a command line. Docker's rule `[a-zA-Z0-9][a-zA-Z0-9_.-]*` excludes
     * whitespace, shell metacharacters and a leading `-`, so a name cannot arrive as a flag.
     */
    public static function validName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/', $name) === 1;
    }

    /**
     * The containers attached to a network, by name.
     *
     * Docker's own error for a network in use names neither the network nor what is on it.
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
     * is where that prefix is decided. Public because it is also what decides
     * which networks a site may join ({@see NetworkIsolation}).
     */
    public static function applicationIdFrom(string $name): ?int
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
