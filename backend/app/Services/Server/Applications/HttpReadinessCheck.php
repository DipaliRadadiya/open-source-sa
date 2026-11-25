<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * After starting an application, ask it for a page.
 *
 * `systemctl is-active` only says the process is running. An app whose assets never compiled
 * (e.g. NodeBB) stays up and answers every request with a 500, so a served page is the
 * evidence, not a command's exit status.
 *
 * Probed on the loopback address and the application's own port, as the reverse proxy does, so
 * DNS, the certificate and the vhost (which have their own checks) are not blamed on the app.
 *
 * **Only 5xx fails.** A redirect to a login page (302), a refused anonymous request (401/403)
 * or no route at `/` (404) is a working application; requiring 2xx would fail healthy installs.
 */
class HttpReadinessCheck
{
    public function __construct(
        private ServerOps $serverOps,
        private ProcessSupervisor $processes,
    ) {}

    /**
     * @throws ProvisioningFailedException
     */
    public function verify(Application $application): void
    {
        $port = (int) $application->app_port;

        if ($port <= 0) {
            // Not served by a process of its own (e.g. PHP sites): nothing to probe.
            return;
        }

        $attempts = max(1, (int) config('server.applications.readiness.attempts', 15));
        $delay = max(0, (int) config('server.applications.readiness.delay', 2));

        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->probe($application, $port);

            // `--write-out %{http_code}` always prints something (`000` if curl could not connect),
            // so empty output means the probe itself did not run (curl missing). Skip rather than
            // fail a healthy application on a measurement that was never taken.
            if ($last['status'] === null) {
                return;
            }

            // A 5xx is the application answering that it is broken; retrying only delays the report.
            if ($last['status'] >= 500) {
                break;
            }

            if ($last['status'] > 0) {
                return;
            }

            // No answer yet: Node applications take a while to boot, so wait and retry.
            if ($attempt < $attempts && $delay > 0) {
                sleep($delay);
            }
        }

        // The probe's log entry holds only a status code; the reason is in the application's
        // journal. Capture it now, while the application is still failing, and give the user that
        // reference (as `assertAssetsBuilt` does): the log that explains, not the one that noticed.
        $diagnostic = $this->captureJournal($application);

        throw new ProvisioningFailedException(
            'verify_serving',
            $diagnostic ?? $last['reference'] ?? '',
            ($last['status'] ?? 0) >= 500 ? 'serving_error' : 'not_answering',
        );
    }

    /**
     * Record the application's own log beside the failure, and return the reference of the
     * entry holding it. Null when the journal could not be read (its empty output would be a
     * worse reference than the probe's).
     *
     * Deliberately swallows its own failure: an error about journalctl must not hide the
     * original "this site answers 500".
     */
    private function captureJournal(Application $application): ?string
    {
        try {
            $result = $this->serverOps->run(
                [
                    'journalctl', '-u', $this->processes->unit($application),
                    '-n', (string) config('server.applications.readiness.journal_lines', 100),
                    '--no-pager',
                ],
                [
                    'feature' => 'application',
                    'op' => 'provision.verify_serving_journal',
                    'application' => $application->id,
                    'log_output' => true,
                ],
                timeout: 30,
            );
        } catch (\Throwable) {
            return null;
        }

        // Only use this reference when it holds something to read.
        return $result->failed() || trim($result->output()) === ''
            ? null
            : $result->reference;
    }

    /**
     * `status` is null when curl wrote no code at all — see verify().
     *
     * @return array{status: int|null, reference: string}
     */
    private function probe(Application $application, int $port): array
    {
        // `-o /dev/null -w %{http_code}` keeps the HTML body out of the ops log; the code is logged.
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
