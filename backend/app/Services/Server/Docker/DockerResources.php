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

        // Fetched once for every network rather than per attachment. `docker
        // network inspect` knows which containers are on a network and nothing
        // about their ports; `docker ps` knows the ports and nothing about the
        // networks. One call each, joined on the name.
        $ports = $this->containerPorts();

        // Which sites NAME a network, which is a different question from which
        // containers are on one right now. A stopped site's container is on
        // nothing, and its compose file still says `external: true` against
        // this name — so deleting the network succeeds and the site fails to
        // start later, with the cause an hour behind the symptom. One query for
        // every network rather than one per row.
        $sites = Application::query()
            ->whereNotNull('docker_network')
            ->get(['id', 'name', 'docker_network'])
            ->groupBy('docker_network');

        // `use ($ports)` is load-bearing and its absence was silent: without
        // it `$ports` is undefined inside the closure, and the `??` below
        // turns that into `false` rather than an error — so every container
        // reported as internal-only, including ones published to the host.
        // The test that distinguishes Ghost from its MySQL is what caught it.
        return array_map(function (array $row) use ($ports, $sites): array {
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
                // The sites that joined it on purpose, by name and id, so a
                // refusal can say "staging-api is on it" instead of "it is in
                // use" — which sends somebody to the terminal to find out
                // which one.
                // `->get($name, collect())`, not `$sites[$name] ?? collect()`.
                // The `??` form swallows an undefined VARIABLE as well as an
                // absent key, which is how the `use ($ports)` bug above stayed
                // silent — and how this one did, until a test asked for a real
                // answer. A missing key here is ordinary; a missing binding is
                // a bug, and the two must not look the same.
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
                        // Whether anything on the host can reach it. The
                        // distinction is the whole security story of this
                        // stack and it is invisible without saying it: Ghost
                        // shows `127.0.0.1:2368->2368/tcp` and its MySQL shows
                        // `3306/tcp` — exposed to its own network, published
                        // nowhere. A reader should be able to see which is
                        // which without knowing that an arrow is what
                        // distinguishes them.
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

        // Asked once for every volume, not once per row — the same rule
        // `networks()` follows for `docker ps`.
        $users = $this->volumeContainers();

        // Which sites MOUNT each volume, which `Links` cannot answer. A stopped
        // site's container is gone, so `Links` is 0 and every check passes — and
        // the volume still holds that site's database. Deleting it there is
        // actual data loss, not a site that fails to start, which makes this the
        // more important of the two guards rather than the mirror of the
        // network one.
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
                        // WHERE the site mounts it, which is the more useful half
                        // of the answer: `/var/lib/mysql` says this volume is a
                        // database, and the host mountpoint says nothing at all.
                        // One row per mount rather than per volume, because a
                        // volume mounted twice is mounted at two paths.
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
                // As Docker renders it — "5.243MB". Not re-parsed into bytes:
                // that would be the panel guessing at a unit, and the only use
                // for the value is to be read.
                'size' => (string) ($row['Size'] ?? ''),
                'containers' => $links,
                // Named, because a count is not actionable: "1 container(s)"
                // tells somebody a number, and what they need is which one to
                // go and stop.
                //
                // `->get()` with a default, never `?? []` — see the note in
                // `networks()` about what the null-coalesce hides.
                'container_names' => $users->get($name, []),
                // The sites configured to mount it, running or not.
                'sites' => $sites->get($name, []),
                // Deliberately still `$links`, not `count($container_names)`.
                // This is what the delete guard reads, and `system df -v` is the
                // stricter source: if `inspect` could not answer, a volume with
                // users must still be refused rather than deleted because the
                // panel failed to name them.
                'in_use' => $links > 0,
                // A volume with no container is not necessarily rubbish — it
                // may belong to a stopped application — so it is labelled
                // rather than swept up.
                'dangling' => $links === 0,
                'application_id' => $this->applicationIdFrom($name),
            ];
        }, $rows);
    }

    /**
     * The containers using each volume, keyed by volume name.
     *
     * **Not `docker ps`, and this is the whole reason the method exists.**
     * `docker ps --format` TRUNCATES the mount list: on a real box two
     * different volumes both came back as `sv-app-7_ghost…`, ellipsis included.
     * Joining on that matches nothing — or, with two volumes sharing a prefix,
     * matches the wrong one. `container inspect` returns the full name and also
     * says `Type`, which is what lets a bind mount be excluded: a site whose
     * compose mounts `/home/ghost/alpha/public_html` is not a user of any
     * volume, and listing it as one would be a lie in the Used-by column.
     *
     * `-a`, so stopped containers count. `system df -v` reports `Links` over
     * all of them, and names taken from running containers only would
     * contradict the number beside them.
     *
     * Two processes, whatever the number of volumes — the rule `networks()`
     * already follows. A `--filter volume=` per row would be a process per row.
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

        // `docker container inspect` with no arguments is an ERROR, not an
        // empty answer — "requires at least 1 argument". On a box with no
        // containers that would be a failed op in the log every time the
        // volumes page loaded.
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
     * Docker renders the mapping as `127.0.0.1:2368->2368/tcp` when a port is
     * published to the host and as a bare `3306/tcp` when it is merely exposed
     * to other containers. **The arrow is the whole difference** — an exposed
     * port is reachable only from the same network, a published one is
     * reachable from the host — and it is far too easy to read the two as the
     * same thing.
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
     * Called before `compose up`, and it exists because `external: true` means
     * Compose will not create them. Two situations reach here:
     *
     *  - The site was created with "make a new network/volume". Nothing has made
     *    it yet; validation only checked the name was free.
     *  - The site has been wired for a while and somebody removed the object with
     *    `docker volume rm` on the box. The panel's own delete refuses that, but
     *    the panel is not the only thing with a shell.
     *
     * Creating rather than failing, in both cases, because the alternative is a
     * site that will not start and a message about a name the user did choose.
     * Idempotent by checking first: `network create` errors on an existing name,
     * and `volume create` silently returns the existing volume — so neither is
     * safe to fire blindly, for opposite reasons.
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
