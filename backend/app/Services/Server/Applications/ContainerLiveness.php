<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * Whether a container site has anything running right now (DS-09).
 *
 * `container_status` is what the last deploy's readiness check saw, and
 * nothing after it is reported back: a container stopped from a shell, killed
 * by the OOM killer three days later, or left stopped by a restore kept a
 * green "Running" over a 502. So a stored `running` is checked against Docker
 * before it is shown.
 *
 * One `docker ps` per request, whatever the number of sites — scoped in the
 * container, the rule `DockerResources` follows for the same reason. When
 * Docker cannot be asked, the answer is null and the stored status stands:
 * a status query that failed is not a container that stopped.
 */
class ContainerLiveness
{
    /**
     * Projects with a running container, each with the worst health Docker
     * reports for them (null: no healthcheck); false when Docker did not answer.
     *
     * @var array<string, string|null>|false|null
     */
    private array|false|null $running = null;

    public function __construct(private ServerOps $serverOps) {}

    public function running(Application $application): ?bool
    {
        $this->running ??= $this->read();

        if ($this->running === false) {
            return null;
        }

        return array_key_exists(app(ContainerSupervisor::class)->project($application), $this->running);
    }

    /**
     * The image's HEALTHCHECK verdict for a running site right now (DS-14):
     * `healthy`, `starting` or `unhealthy`, the worst of its containers. Null
     * when nothing runs, the image has no healthcheck, or Docker did not answer.
     * Read from the same single `docker ps` as {@see running()}.
     */
    public function health(Application $application): ?string
    {
        $this->running ??= $this->read();

        if ($this->running === false) {
            return null;
        }

        return $this->running[app(ContainerSupervisor::class)->project($application)] ?? null;
    }

    /**
     * @return array<string, string|null>|false
     */
    private function read(): array|false
    {
        $result = $this->serverOps->run(
            ['docker', 'ps', '--filter', 'status=running', '--format', '{{.Label "com.docker.compose.project"}}\t{{.Status}}'],
            ['feature' => 'application', 'op' => 'container_liveness'],
            timeout: 15,
        );

        if (! $result->answered) {
            return false;
        }

        $projects = [];
        $rank = ['healthy' => 1, 'starting' => 2, 'unhealthy' => 3];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            [$project, $status] = array_pad(explode("\t", rtrim($line), 2), 2, '');

            if (($project = trim($project)) === '') {
                continue;
            }

            // `Up 7 minutes (unhealthy)`, `Up 5 seconds (health: starting)`.
            $health = match (true) {
                str_contains($status, '(unhealthy)') => 'unhealthy',
                str_contains($status, 'health: starting') => 'starting',
                str_contains($status, '(healthy)') => 'healthy',
                default => null,
            };

            $current = $projects[$project] ?? null;

            if (! array_key_exists($project, $projects) || ($rank[$health ?? ''] ?? 0) > ($rank[$current ?? ''] ?? 0)) {
                $projects[$project] = $health;
            }
        }

        return $projects;
    }
}
