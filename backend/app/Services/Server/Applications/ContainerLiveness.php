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
    /** @var array<string, true>|false|null projects with a running container; false when Docker did not answer */
    private array|false|null $running = null;

    public function __construct(private ServerOps $serverOps) {}

    public function running(Application $application): ?bool
    {
        $this->running ??= $this->read();

        if ($this->running === false) {
            return null;
        }

        return isset($this->running[app(ContainerSupervisor::class)->project($application)]);
    }

    /**
     * @return array<string, true>|false
     */
    private function read(): array|false
    {
        $result = $this->serverOps->run(
            ['docker', 'ps', '--filter', 'status=running', '--format', '{{.Label "com.docker.compose.project"}}'],
            ['feature' => 'application', 'op' => 'container_liveness'],
            timeout: 15,
        );

        if (! $result->answered) {
            return false;
        }

        $projects = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (($line = trim($line)) !== '') {
                $projects[$line] = true;
            }
        }

        return $projects;
    }
}
