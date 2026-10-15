<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use Illuminate\Support\Sleep;

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
 * An image with its own HEALTHCHECK is asked that instead (DS-14): its
 * author's idea of ready beats a GET of `/`, and the panel had said Running
 * over a Kanboard that served a 200 error page while Docker called it
 * unhealthy. Those sites can also read `starting` and `unhealthy`.
 *
 * On failure the reason is stored on the application in words a person can act
 * on — "Nothing answers on container port 8082 — the image listens on 5230" —
 * with the last lines of the container's own log beside it.
 */
class ContainerReadinessCheck
{
    /**
     * The longest wait, whatever is configured: `PUT /container` and `pull`
     * run this inside a request the panel's vhost cuts off at 300 s, after a
     * pull and a `compose up` that take their own share of it.
     *
     * It bounds this check, not the request (DS-12). The check's own docker
     * questions are capped at `ContainerSupervisor::QUERY_TIMEOUT`, so the
     * wait and the diagnostics after it stay under the 300 s even at their
     * worst for a single-container site (a pasted stack adds a 20 s `docker
     * inspect` per container to the last try, normally milliseconds). The
     * `compose up`/`pull` before it run under `server.docker.command_timeout`
     * (600 s), because an image may be gigabytes, and a slow pull can still
     * take the request past the FastCGI timeout on its own.
     */
    public const MAX_TIMEOUT = 150;

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Wait — against the clock, not a count of tries — for the site to answer.
     *
     * A deadline, because the tries were never free (DS-09): each one is a
     * `compose ps`, a `docker inspect` per container and a curl that can sit
     * on a hung port for its full timeout, so "90 seconds" waited up to ~258.
     * `PUT /container` and `pull` wait for this synchronously, behind the
     * panel's own 300 s FastCGI timeout, and past it the user got a 504
     * instead of the reason. The configured value is capped well under it —
     * see MAX_TIMEOUT for what that does and does not bound.
     *
     * An answer always wins. A restart is only a failure once it has gone on
     * for `restart_grace` seconds with nothing answering — and in a pasted
     * file, never before the deadline: there, one service exiting once while
     * its database starts is how a healthy stack boots, and a single sighting
     * failed it (DS-09). The restart is still what the failure names.
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
        // With no interval between tries (the test suite) nothing guarantees
        // the clock moves, so there the tries are counted instead.
        $attempts = $interval > 0 ? PHP_INT_MAX : $timeout;
        $pasted = trim((string) $application->compose) !== '';

        $started = now();
        $deadline = $started->copy()->addSeconds($timeout);
        $loopingSince = null;
        $looping = false;

        $health = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $remaining = (int) ceil(now()->diffInSeconds($deadline, false));

            // The image's own HEALTHCHECK, when it has one, decides (DS-14):
            // healthy passes, unhealthy fails with the check's own output.
            // While it is starting, `/` is not asked at all — Docker is already
            // asking, and a second requester during a first boot is what left
            // Kanboard's lazy migration half applied.
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

            $status = $health === 'starting' ? 0 : $this->probe($application, $port, max(1, min(5, $remaining)));

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

                // curl wrote nothing: the probe did not happen, so nothing was
                // measured. With the container up and not bouncing, calling the
                // site broken on that would be a guess — the same rule as
                // HttpReadinessCheck.
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

        // Still starting at the deadline. Some images give their check minutes
        // of start period, and holding a deploy that long is worse than saying
        // so: one request to `/` now, and an answer passes as `starting` —
        // the live status turns it into running or unhealthy once Docker has.
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

        // No container port to name — a pasted file publishes its own — and
        // `app_port` is the panel's loopback port on the HOST, which the
        // sentence would have sent the user off to set as the container's.
        $this->fail($application, $documentRoot, $containers, 'not_answering', 'container_not_answering', [
            ...($containerPort > 0 && ! $pasted ? ['port' => $containerPort] : []),
            'seconds' => $waited,
        ]);
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
