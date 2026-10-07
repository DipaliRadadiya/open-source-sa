<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * After `compose up`, wait for the site to actually answer (DS-03).
 *
 * `compose up -d` exits 0 for a container that dies a second later, and for one
 * that runs perfectly while listening on a port nginx is not proxying to. The
 * second is the trap the old "Container port" field set: Memos given 8082 ran
 * happily on 5230, the panel said Running, and every request was a 502.
 *
 * So the check asks the two questions a user would: is the container staying
 * up, and does anything answer on its port? Any HTTP status counts as an
 * answer — a login redirect, a 401 or a 404 at `/` are all a working app. The
 * {@see HttpReadinessCheck} for systemd apps fails on 5xx; this one does not,
 * because a container app that answers 500 on its first boot is usually still
 * migrating, and the user can see that in its log, which is now kept.
 *
 * On failure the reason is stored on the application in words a person can act
 * on — "Nothing answers on container port 8082 — the image listens on 5230" —
 * with the last lines of the container's own log beside it.
 */
class ContainerReadinessCheck
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @throws ProvisioningFailedException
     */
    public function verify(Application $application, string $documentRoot, ContainerSupervisor $containers): void
    {
        $port = (int) $application->app_port;

        if ($port <= 0) {
            return;
        }

        $timeout = max(1, (int) config('server.docker.readiness.timeout', 90));
        $interval = max(0, (int) config('server.docker.readiness.interval', 3));
        $attempts = max(1, intdiv($timeout, max(1, $interval)));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($containers->crashLooping($application, $documentRoot)) {
                $this->fail($application, $documentRoot, $containers, 'restarting', 'container_restarting');
            }

            if (! $containers->running($application, $documentRoot)) {
                $this->fail($application, $documentRoot, $containers, 'exited', 'container_exited');
            }

            $status = $this->probe($application, $port);

            // curl wrote nothing: the probe did not happen, so nothing was
            // measured. Calling the site broken on that would be a guess — the
            // same rule as HttpReadinessCheck.
            if ($status === null || $status > 0) {
                $this->passed($application);

                return;
            }

            if ($attempt < $attempts && $interval > 0) {
                sleep($interval);
            }
        }

        $declared = $this->declaredPorts($application);
        $containerPort = (int) ($application->container_port ?: 0);

        if ($containerPort > 0 && $declared !== [] && ! in_array($containerPort, $declared, true)) {
            $this->fail($application, $documentRoot, $containers, 'not_answering', 'container_port_mismatch', [
                'port' => $containerPort,
                'image_ports' => implode(', ', $declared),
            ]);
        }

        $this->fail($application, $documentRoot, $containers, 'not_answering', 'container_not_answering', [
            'port' => $containerPort > 0 ? $containerPort : $port,
            'seconds' => $timeout,
        ]);
    }

    private function passed(Application $application): void
    {
        $application->forceFill([
            'container_status' => 'running',
            'last_failure' => null,
        ])->save();
    }

    /**
     * Record why, with the container's own last words, and stop the deploy.
     *
     * @param  array<string, int|string>  $params
     *
     * @throws ProvisioningFailedException
     */
    private function fail(
        Application $application,
        string $documentRoot,
        ContainerSupervisor $containers,
        string $status,
        string $reason,
        array $params = [],
    ): never {
        $log = $this->tail($application, $documentRoot, $containers);

        $application->forceFill([
            'container_status' => $status,
            'last_failure' => [
                'reason' => $reason,
                'params' => $params + ['last_line' => self::lastLine($log)],
                'log' => $log,
                'at' => now()->toIso8601String(),
            ],
        ])->save();

        // The step names the restart-loop and exit cases have always had, so a
        // client keyed on them reads these the same as before.
        $step = match ($reason) {
            'container_restarting' => 'container_restarting',
            'container_exited' => 'container_exited',
            default => 'verify_serving',
        };

        throw new ProvisioningFailedException($step, '', $reason);
    }

    /**
     * The container's last log lines. Empty when they could not be read — a
     * diagnostic must never be the thing that fails.
     */
    private function tail(Application $application, string $documentRoot, ContainerSupervisor $containers): string
    {
        try {
            return trim($containers->logs($application, $documentRoot, (int) config('server.docker.readiness.log_lines', 50)));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The last thing the app said, without compose's `app-1  | ` prefix.
     */
    public static function lastLine(string $log): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $log) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            return '';
        }

        $line = (string) preg_replace('/^[A-Za-z0-9._-]+\s+\|\s?/', '', end($lines));

        return mb_strimwidth($line, 0, 300, '…');
    }

    /**
     * Ports the image declares (EXPOSE), read from the copy on this server —
     * it was just pulled, so nothing goes to a registry. Pasted compose files
     * have no single image, so they get none.
     *
     * @return list<int>
     */
    private function declaredPorts(Application $application): array
    {
        $image = (string) $application->image;

        if ($image === '' || trim((string) $application->compose) !== '') {
            return [];
        }

        $result = $this->serverOps->run(
            ['docker', 'image', 'inspect', '--format', '{{json .Config.ExposedPorts}}', $image],
            ['feature' => 'application', 'op' => 'image_ports', 'application' => $application->id],
            timeout: 20,
        );

        $ports = json_decode(trim($result->output()), true);

        if ($result->failed() || ! is_array($ports)) {
            return [];
        }

        $declared = [];

        foreach (array_keys($ports) as $key) {
            if (preg_match('#^(\d+)(/tcp)?$#', (string) $key, $match) === 1) {
                $declared[] = (int) $match[1];
            }
        }

        sort($declared);

        return array_values(array_unique($declared));
    }

    /**
     * The HTTP status on the loopback port, 0 for no answer, null when curl
     * printed nothing at all.
     */
    private function probe(Application $application, int $port): ?int
    {
        $result = $this->serverOps->run(
            [
                'curl', '--silent', '--output', '/dev/null',
                '--write-out', '%{http_code}',
                '--max-time', '5',
                "http://127.0.0.1:{$port}/",
            ],
            [
                'feature' => 'application',
                'op' => 'container_readiness',
                'application' => $application->id,
                'log_output' => true,
            ],
            timeout: 15,
        );

        $written = trim($result->output());

        return $written === '' ? null : (int) $written;
    }
}
