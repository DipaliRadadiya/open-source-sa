<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\Panel\QueueWorker;
use App\Services\Server\Certificates\AcmeReachabilityCheck;
use Throwable;

/**
 * Gives a new site HTTPS without anyone asking, when that is actually possible.
 *
 * Runs at the end of provisioning, once the vhost is live (the challenge is served by it).
 *
 * **Silence is the design.** For a new domain the DNS record usually does not point here yet, so
 * this declines. A decline writes nothing (no row, no activity entry, `certificate: null`), or
 * every new site would show a red SSL error about something the user has not set up yet.
 *
 * There is deliberately no retry sweep: the install button is one click and says why it declines.
 */
class AutoIssueCertificate
{
    public function __construct(private AcmeReachabilityCheck $reachability) {}

    public function execute(Application $application): ?Certificate
    {
        if (! config('server.certificates.auto_issue', true)) {
            return null;
        }

        // Never overwrite an existing certificate: provisioning can be re-run, and reissuing
        // spends rate limit for nothing.
        if ($application->certificate !== null) {
            return null;
        }

        // Every name the site has. The dry run below decides, not the shape of the suffix.
        $candidates = $application->domains->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $passed = $this->reachable($candidates);

        if ($passed === []) {
            return null;
        }

        $certificate = Certificate::create([
            'application_id' => $application->id,
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Pending,
            'domains' => $passed,
            'auto_renew' => true,
        ]);

        // No actor: reads as System in the activity log, like a git-webhook deploy.
        IssueCertificate::dispatch($certificate->id, null)
            ->onQueue(app(QueueWorker::class)->priorityQueue());

        return $certificate;
    }

    /**
     * The names that answered the dry-run challenge.
     *
     * Retried briefly when the only problem is that the challenge was not served yet:
     * `systemctl reload` returns before the new web server workers take over, so a site reaching
     * this step right after it fails the check. Any other failure (DNS elsewhere, a proxy) is a
     * real answer and is not retried.
     *
     * @return array<int, string>
     */
    private function reachable($candidates): array
    {
        $attempts = max(1, (int) config('server.certificates.auto_issue_attempts', 4));
        $delay = (int) config('server.certificates.auto_issue_retry_seconds', 2);

        for ($attempt = 1; ; $attempt++) {
            $results = $this->reachability->checkAll($candidates);
            $passed = array_values(array_map(
                fn (array $result) => $result['domain'],
                array_filter($results, fn (array $result) => $result['ok']),
            ));

            $notYet = collect($results)->contains(fn (array $result) => ! $result['ok'] && ($result['reason'] ?? null) === 'challenge_not_served');

            if ($passed !== [] || ! $notYet || $attempt >= $attempts) {
                return $passed;
            }

            if ($delay > 0) {
                sleep($delay);
            }
        }
    }

    /**
     * Run without being able to break the caller.
     *
     * Provisioning has already succeeded by now; a DNS timeout or unreachable host must not turn
     * a working site into a failed application.
     */
    public function attempt(Application $application): void
    {
        try {
            $this->execute($application);
        } catch (Throwable) {
            // Deliberately swallowed: the install button is still there.
        }
    }
}
