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
 * Runs at the end of provisioning, once the vhost is live — which it has to be,
 * since the challenge is served by it.
 *
 * **Silence is the design.** For a genuinely new domain the DNS record almost
 * never points here yet, so most of the time this declines. If declining wrote
 * a `failed` certificate, every new site would be born showing a red SSL error
 * about something the user has not set up yet. So a decline writes nothing at
 * all: no row, no activity entry, `certificate: null`, and the SSL screen shows
 * its ordinary install button.
 *
 * Where it does pay off is the case where DNS was pointed in advance — a site
 * migrated from another server, a domain re-pointed before the site was
 * created. For those, HTTPS simply exists.
 *
 * There is deliberately no retry sweep. The button is one click and now
 * explains precisely why it says no, which is worth more than a background job
 * acting while nobody is watching.
 */
class AutoIssueCertificate
{
    public function __construct(private AcmeReachabilityCheck $reachability) {}

    public function execute(Application $application): ?Certificate
    {
        if (! config('server.certificates.auto_issue', true)) {
            return null;
        }

        // Never overwrite a certificate that is already there. Provisioning can
        // be re-run, and reissuing on top of a working one spends rate limit to
        // achieve nothing.
        if ($application->certificate !== null) {
            return null;
        }

        // Every name the site has. A hostname is not disqualified by the shape
        // of its suffix — the dry run below decides, the same way it does for a
        // domain somebody registered themselves.
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

        // No actor: nobody pressed anything. Reads as System in the activity
        // log, the same as a deploy triggered by a git webhook.
        IssueCertificate::dispatch($certificate->id, null)
            ->onQueue(app(QueueWorker::class)->priorityQueue());

        return $certificate;
    }

    /**
     * The names that answered the dry-run challenge.
     *
     * Asked again, briefly, when the only problem is that the challenge was
     * not served yet (bug #52). `systemctl reload` returns before the web
     * server's new workers take over, and a Blank PHP, Static, Git, Staging
     * or Clone site reaches this step straight after that reload — the old
     * workers do not know the new name, so the check failed and no
     * certificate was issued, while one-click apps, which install for a
     * while first, got theirs. Measured on the nginx test server: the same
     * check minutes later passed. Anything else (DNS elsewhere, a proxy) is a
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
     * Run without being able to break the thing that called it.
     *
     * Provisioning has already succeeded by the time this runs — the site is
     * created, serving and correct. A DNS timeout or an unreachable host must
     * not turn that into a failed application, because the user would lose a
     * working site over a certificate they never asked for.
     */
    public function attempt(Application $application): void
    {
        try {
            $this->execute($application);
        } catch (Throwable) {
            // Deliberately swallowed. The install button is still there.
        }
    }
}
