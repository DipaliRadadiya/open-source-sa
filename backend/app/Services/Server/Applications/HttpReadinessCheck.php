<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * After starting an application, ask it for a page.
 *
 * `systemctl is-active` was the strongest thing provisioning checked, and it
 * answers a narrower question than anyone reading it assumed: whether the
 * process is running. A NodeBB whose assets never compiled runs perfectly. It
 * accepts connections, stays up, satisfies `is-active` — and answers every
 * request with `500 Internal server error. Failed to lookup view!`. The panel
 * reported that site as Active, because by every check it had, it was.
 *
 * This is the same detect-don't-trust rule the panel already applies one level
 * down — `php-fpm -t` before a reload, `is-active` after a start — moved up to
 * the thing the user actually experiences. A command's exit status is a claim;
 * a served page is the evidence.
 *
 * Probed on the loopback address and the application's own port, not through
 * the domain. That is exactly what the reverse proxy in front of it does, so
 * it tests the application rather than DNS, the certificate or the vhost —
 * which have their own checks and their own failures, and would otherwise be
 * reported here as if the application were broken.
 *
 * **Only 5xx fails.** A forum that redirects to a login page (302), or refuses
 * an anonymous request (401/403), or has no route at `/` (404) is a working
 * application answering correctly. Treating anything but 2xx as broken would
 * fail installs that are completely fine.
 */
class HttpReadinessCheck
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * @throws ProvisioningFailedException
     */
    public function verify(Application $application): void
    {
        $port = (int) $application->app_port;

        if ($port <= 0) {
            // Nothing to probe: this application is not served by a process of
            // its own. Silence rather than a failure — PHP sites are served by
            // the web server and have no port to ask.
            return;
        }

        $attempts = max(1, (int) config('server.applications.readiness.attempts', 15));
        $delay = max(0, (int) config('server.applications.readiness.delay', 2));

        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->probe($application, $port);

            // Nothing was measured. `--write-out %{http_code}` always prints
            // something — `000` when curl could not connect at all — so empty
            // output does not mean "no answer", it means the probe itself did
            // not happen: curl missing, or the command not actually run.
            //
            // Skipping is the honest response. Failing here would report a
            // healthy application as broken on the strength of a measurement
            // that was never taken, which is the exact mistake this class was
            // written to stop making one level down.
            if ($last['status'] === null) {
                return;
            }

            // A 5xx is the application answering that it is broken, and it will
            // still be broken in two seconds. Retrying only delays the report.
            if ($last['status'] >= 500) {
                break;
            }

            if ($last['status'] > 0) {
                return;
            }

            // No answer yet. Node applications take a while to boot — the
            // connection being refused a second after `systemctl start` says
            // nothing at all, so this is the case worth waiting on.
            if ($attempt < $attempts && $delay > 0) {
                sleep($delay);
            }
        }

        throw new ProvisioningFailedException(
            'verify_serving',
            $last['reference'] ?? '',
            ($last['status'] ?? 0) >= 500 ? 'serving_error' : 'not_answering',
        );
    }

    /**
     * `status` is null when curl wrote no code at all — see verify().
     *
     * @return array{status: int|null, reference: string}
     */
    private function probe(Application $application, int $port): array
    {
        // `-o /dev/null -w %{http_code}` keeps the body out of the ops log: a
        // 500 page is HTML, and the status is the part that decides anything.
        // `log_output` still records the code, which is what a reader wants.
        $result = $this->serverOps->run(
            [
                'curl', '--silent', '--show-error', '--output', '/dev/null',
                '--write-out', '%{http_code}',
                '--max-time', (string) config('server.applications.readiness.timeout', 10),
                "http://127.0.0.1:{$port}/",
            ],
            [
                'feature' => 'application',
                'op' => 'provision.verify_serving',
                'application' => $application->id,
                'log_output' => true,
            ],
            timeout: 30,
        );

        $written = trim($result->output());

        return [
            'status' => $written === '' ? null : (int) $written,
            'reference' => $result->reference,
        ];
    }
}
