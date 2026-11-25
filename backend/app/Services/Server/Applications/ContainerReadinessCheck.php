<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * After `compose up`, wait for the site to actually answer.
 *
 * `compose up -d` exits 0 for a container that dies a second later, and for one listening on a
 * port nginx is not proxying to (every request is then a 502). So this asks: does the container
 * stay up, and does anything answer on its port?
 *
 * Any HTTP status counts as an answer (a 500 on first boot is usually a migration still running),
 * unlike {@see HttpReadinessCheck} for systemd apps, which fails on 5xx.
 *
 * An image with its own HEALTHCHECK is asked that instead, and can read `starting` and `unhealthy`.
 * A container on a non-HTTP port (PostgreSQL, Redis) is never asked for `/`; its own state
 * decides: HEALTHCHECK if it has one, otherwise running at two consecutive looks.
 *
 * On failure the reason is stored on the application in words a person can act on, with the
 * last lines of the container's log.
 */
class ContainerReadinessCheck
{
    /**
     * The longest wait, whatever is configured: `PUT /container` and `pull` run this inside a
     * request the vhost cuts off at 300 s.
     *
     * It bounds this check, not the request: the `compose up`/`pull` before it run under
     * `server.docker.command_timeout` (600 s) and a slow pull can exceed the FastCGI timeout alone.
     */
    public const MAX_TIMEOUT = 150;

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Wait, against a deadline rather than a count of tries, for the site to answer.
     *
     * Each try costs a `compose ps`, a `docker inspect` per container and a curl, so a count could
     * run far past the 300 s FastCGI timeout; see MAX_TIMEOUT.
     *
     * An answer always wins. A restart only fails after `restart_grace` seconds with nothing
     * answering, and in a pasted file never before the deadline (a service may exit once while its
     * database starts). The restart is still what the failure names.
     *
     * @throws ProvisioningFailedException
     */
    public function verify(Application $application, string $documentRoot, ContainerSupervisor $containers): void
    {
        $port = (int) $application->app_port;

        if ($port <= 0) {
            return;
        }

        $timeout = min(self::MAX_TIMEOUT, max(1, (int) config('server.docker.readiness.timeout', 90)));
        $interval = max(0, (int) config('server.docker.readiness.interval', 3));
        $grace = max(0, (int) config('server.docker.readiness.restart_grace', 30));
        // With no interval (the test suite) the clock may not move, so tries are counted instead.
        $attempts = $interval > 0 ? PHP_INT_MAX : $timeout;
        $pasted = trim((string) $application->compose) !== '';
        $nonHttp = ! $pasted && $this->speaksOtherProtocol($application);
        $seenRunning = 0;

        $started = now();
        $deadline = $started->copy()->addSeconds($timeout);
        $loopingSince = null;
        $looping = false;

        $health = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $remaining = (int) ceil(now()->diffInSeconds($deadline, false));

            // The image's own HEALTHCHECK decides: healthy passes, unhealthy fails with its output.
            // While starting, `/` is not asked: a second requester during first boot can leave a
            // lazy migration half applied.
            $health = $containers->health($application, $documentRoot);

            if ($health === 'healthy') {
                $this->passed($application);

                return;
            }

            if ($health === 'unhealthy') {
                $this->fail($application, $documentRoot, $containers, 'unhealthy', 'container_unhealthy', [
                    'check' => self::lastLine($containers->healthOutput($application, $documentRoot)),
                ]);
            }

            $status = $health === 'starting' || $nonHttp ? 0 : $this->probe($application, $port, max(1, min(5, $remaining)));

            if ($status !== null && $status > 0) {
                $this->passed($application);

                return;
            }

            $looping = $containers->crashLooping($application, $documentRoot);

            if ($looping) {
                $loopingSince ??= now();

                if (! $pasted && $loopingSince->diffInSeconds(now()) >= $grace) {
                    $this->fail($application, $documentRoot, $containers, 'restarting', 'container_restarting');
                }
            } else {
                $loopingSince = null;

                if (! $containers->running($application, $documentRoot)) {
                    $this->fail($application, $documentRoot, $containers, 'exited', 'container_exited');
                }

                // Twice: `compose up` returns before a badly configured container has exited.
                if ($nonHttp && $health === null && ++$seenRunning >= 2) {
                    $this->passed($application);

                    return;
                }

                // curl wrote nothing, so nothing was measured; with the container up that is not
                // a failure (same rule as HttpReadinessCheck).
                if ($status === null) {
                    $this->passed($application);

                    return;
                }
            }

            if ($attempt === $attempts || now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            if ($interval > 0) {
                Sleep::for(min($interval, max(1, (int) ceil(now()->diffInSeconds($deadline, false)))))->seconds();
            }
        }

        if ($looping) {
            $this->fail($application, $documentRoot, $containers, 'restarting', 'container_restarting');
        }

        // Still starting at the deadline: some images have minutes of start period. One request to
        // `/` now; an answer passes as `starting` and the live status settles it later.
        if ($health === 'starting' && $nonHttp) {
            $this->passed($application, 'starting');

            return;
        }

        if ($health === 'starting') {
            $status = $this->probe($application, $port, 5);

            if ($status !== null && $status > 0) {
                $this->passed($application, 'starting');

                return;
            }
        }

        $declared = $this->declaredPorts($application);
        $containerPort = (int) ($application->container_port ?: 0);
        $waited = (int) round($started->diffInSeconds(now()));

        if ($containerPort > 0 && $declared !== [] && ! in_array($containerPort, $declared, true)) {
            $this->fail($application, $documentRoot, $containers, 'not_answering', 'container_port_mismatch', [
                'port' => $containerPort,
                'image_ports' => implode(', ', $declared),
            ]);
        }

        // A pasted file has no container port to name, and `app_port` is the host's loopback port.
        $this->fail($application, $documentRoot, $containers, 'not_answering', 'container_not_answering', [
            ...($containerPort > 0 && ! $pasted ? ['port' => $containerPort] : []),
            'seconds' => $waited,
        ]);
    }

    /**
     * Whether the container port is one a non-HTTP service owns.
     *
     * By port, because through docker-proxy a database that drops the request looks exactly like a
     * web app still booting (accepted connection, empty reply).
     */
    private function speaksOtherProtocol(Application $application): bool
    {
        return in_array(
            (int) $application->container_port,
            array_map('intval', (array) config('server.docker.readiness.non_http_ports', [])),
            true,
        );
    }

    private function passed(Application $application, string $status = 'running'): void
    {
        $application->forceFill([
            'container_status' => $status,
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

        // Keep the established step names so clients keyed on them still work.
        $step = match ($reason) {
            'container_restarting' => 'container_restarting',
            'container_exited' => 'container_exited',
            default => 'verify_serving',
        };

        // The verdict comes from probing, not one failed command, so there is no server-ops line to
        // point at; log it under its own reference (the container log is stored above).
        $reference = (string) Str::uuid();

        Log::channel('server-ops')->warning('container readiness failed', [
            'feature' => 'application',
            'op' => 'container_readiness',
            'application' => $application->id,
            'reference' => $reference,
            'step' => $step,
            'reason' => $reason,
            'status' => $status,
        ]);

        throw new ProvisioningFailedException($step, $reference, $reason);
    }

    /**
     * The container's last log lines; empty when unreadable (a diagnostic must never fail).
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
     * Ports the image declares (EXPOSE), read from the local copy; pasted compose files get none.
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
    private function probe(Application $application, int $port, int $maxTime): ?int
    {
        $result = $this->serverOps->run(
            [
                'curl', '--silent', '--output', '/dev/null',
                '--write-out', '%{http_code}',
                '--max-time', (string) $maxTime,
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
