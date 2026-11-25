<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\ServerOps;

/**
 * Whether a container site has anything running right now.
 *
 * `container_status` is only what the last deploy's readiness check saw, so a stored `running`
 * is checked against Docker before it is shown (a later stop or OOM kill would keep a green
 * "Running" over a 502).
 *
 * One `docker ps` per request, whatever the number of sites. When Docker cannot be asked the
 * answer is null and the stored status stands: a failed query is not a stopped container.
 */
class ContainerLiveness
{
    /**
     * The containers of every compose project, running or not; false when Docker did not answer.
     *
     * @var array<string, list<array{service: string, state: string, health: ?string, code: ?int, oneoff: bool}>>|false|null
     */
    private array|false|null $projects = null;

    public function __construct(private ServerOps $serverOps) {}

    public function running(Application $application): ?bool
    {
        $containers = $this->containers($application);

        if ($containers === null) {
            return null;
        }

        foreach ($containers as $container) {
            if ($container['state'] === 'running') {
                return true;
            }
        }

        return false;
    }

    /**
     * The image's HEALTHCHECK verdict for a running site: `healthy`, `starting` or `unhealthy`,
     * the worst of its running containers. Null when nothing runs, there is no healthcheck, or
     * Docker did not answer.
     *
     * Every container counts, helpers too: the deploy-time readiness check reads this and stays
     * strict.
     */
    public function health(Application $application): ?string
    {
        return $this->worstHealth(array_filter(
            $this->containers($application) ?? [],
            fn (array $container): bool => $container['state'] === 'running',
        ));
    }

    /**
     * Whether the app's MAIN part works right now, and what is wrong with the rest:
     * `{status, warnings}`, or null when Docker did not answer or nothing runs (a stop, which
     * {@see running()} reports).
     *
     * Every container is essential unless its recipe marks the service `optional`, so a wrong
     * guess costs a warning, never a hidden outage. `status` is the worst essential problem
     * (`exited` also covers paused/dead, `restarting`, `unhealthy`), else `starting` while a
     * healthcheck has no verdict or an essential container has not started, else `healthy`,
     * else null (no healthcheck). `warnings` lists problem containers, essential first.
     *
     * @return array{status: ?string, warnings: list<array{service: string, state: string}>}|null
     */
    public function verdict(Application $application): ?array
    {
        $containers = $this->containers($application);

        if ($containers === null || $this->running($application) !== true) {
            return null;
        }

        $recipe = app(RecipeRegistry::class)->find((string) $application->site_type);
        $optional = $recipe?->optionalServices() ?? [];
        $essential = [];
        $helpers = [];
        $fine = [];

        foreach ($containers as $container) {
            // `docker compose run` leaves its container behind once it ends.
            if ($container['oneoff']) {
                continue;
            }

            // A recipe's services all run `unless-stopped`, so any stopped one is down whatever
            // its exit code (`docker stop` can exit 0). A pasted compose file may hold a job
            // meant to finish, so there only a non-zero exit is a failure.
            $problem = $this->problem($container, $recipe !== null);

            if ($problem === null) {
                $fine[] = $container;
            } elseif (in_array($container['service'], $optional, true)) {
                $helpers[] = ['service' => $container['service'], 'state' => $problem];
            } else {
                $essential[] = ['service' => $container['service'], 'state' => $problem];
            }
        }

        // `created` is what `compose up` leaves a service in while it waits on a dependency's
        // healthcheck: starting, not failed.
        $rank = ['starting' => 1, 'unhealthy' => 2, 'restarting' => 3, 'exited' => 4];
        $shown = fn (array $warning): string => match ($warning['state']) {
            'unhealthy', 'restarting' => $warning['state'],
            'created' => 'starting',
            default => 'exited',
        };

        // Worst first, so the first warning is the container behind `status`.
        usort($essential, fn (array $a, array $b): int => $rank[$shown($b)] <=> $rank[$shown($a)]);
        $status = $essential === [] ? null : $shown($essential[0]);

        if ($status === null || $status === 'starting') {
            $status = $this->worstHealth([
                ...array_filter($fine, fn (array $container): bool => $container['state'] === 'running'),
                ...($status === 'starting' ? [['health' => 'starting']] : []),
            ]);
        }

        return [
            'status' => $status,
            'warnings' => array_slice([...$essential, ...$helpers], 0, 10),
        ];
    }

    /**
     * What is wrong with one container: `unhealthy`, `restarting`, `exited` (also dead),
     * `created` (never started) or `paused`; null when nothing is.
     *
     * @param  array{service: string, state: string, health: ?string, code: ?int, oneoff: bool}  $container
     */
    private function problem(array $container, bool $longRunning): ?string
    {
        return match ($container['state']) {
            'running' => $container['health'] === 'unhealthy' ? 'unhealthy' : null,
            'restarting', 'created', 'paused' => $container['state'],
            'dead' => 'exited',
            'exited' => $longRunning || $container['code'] !== 0 ? 'exited' : null,
            default => null,
        };
    }

    /**
     * @param  array<array{health: ?string}>  $containers
     */
    private function worstHealth(array $containers): ?string
    {
        $rank = ['healthy' => 1, 'starting' => 2, 'unhealthy' => 3];
        $worst = null;

        foreach ($containers as $container) {
            if (($rank[$container['health'] ?? ''] ?? 0) > ($rank[$worst ?? ''] ?? 0)) {
                $worst = $container['health'];
            }
        }

        return $worst;
    }

    /**
     * @return list<array{service: string, state: string, health: ?string, code: ?int, oneoff: bool}>|null
     */
    private function containers(Application $application): ?array
    {
        $this->projects ??= $this->read();

        if ($this->projects === false) {
            return null;
        }

        return $this->projects[app(ContainerSupervisor::class)->project($application)] ?? [];
    }

    /**
     * @return array<string, list<array{service: string, state: string, health: ?string, code: ?int, oneoff: bool}>>|false
     */
    private function read(): array|false
    {
        $result = $this->serverOps->run(
            ['docker', 'ps', '-a', '--filter', 'label=com.docker.compose.project', '--format',
                '{{.Label "com.docker.compose.project"}}\t{{.Label "com.docker.compose.service"}}\t{{.Label "com.docker.compose.oneoff"}}\t{{.State}}\t{{.Status}}'],
            ['feature' => 'application', 'op' => 'container_liveness'],
            timeout: 15,
        );

        if (! $result->answered) {
            return false;
        }

        $projects = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            [$project, $service, $oneoff, $state, $status] = array_pad(explode("\t", rtrim($line), 5), 5, '');

            if (($project = trim($project)) === '') {
                continue;
            }

            $projects[$project][] = [
                'service' => trim($service),
                'state' => strtolower(trim($state)),
                // `Up 7 minutes (unhealthy)`, `Up 5 seconds (health: starting)`.
                'health' => match (true) {
                    str_contains($status, '(unhealthy)') => 'unhealthy',
                    str_contains($status, 'health: starting') => 'starting',
                    str_contains($status, '(healthy)') => 'healthy',
                    default => null,
                },
                // `Exited (137) 2 minutes ago`.
                'code' => preg_match('/^Exited \((-?\d+)\)/', trim($status), $m) ? (int) $m[1] : null,
                'oneoff' => strcasecmp(trim($oneoff), 'True') === 0,
            ];
        }

        return $projects;
    }
}
