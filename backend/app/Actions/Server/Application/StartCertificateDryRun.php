<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Jobs\DryRunCertificate;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Panel\QueueWorker;
use App\Services\Server\Certificates\CertificateDryRunStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Starts a rehearsal of a Let's Encrypt issuance.
 *
 * Deliberately has no `force` option, unlike `RequestCertificate`: force exists there to
 * get past a wrong local check (a NAT'd box); here the whole operation is the check.
 */
class StartCertificateDryRun
{
    public function __construct(
        private CertificateDryRunStore $store,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function execute(Application $application): array
    {
        // Already going: return it and dispatch no second job. Two certbot processes on one
        // lineage contend for /var/lib/letsencrypt's lock, and one would report a failure
        // that says nothing about the domain.
        if ($this->store->isRunning($application->id)) {
            return $this->store->get($application->id) ?? $this->store->start($application->id);
        }

        // An issuance in flight holds the same lock; refuse with a clear message instead of
        // failing obscurely later.
        $certificate = $application->certificate;

        if ($certificate !== null && in_array($certificate->status, [CertificateStatus::Pending, CertificateStatus::Issuing], true)) {
            throw ValidationException::withMessages([
                'certificate' => [__('errors/certificate.dry_run.issue_in_flight')],
            ]);
        }

        // Deliberately not gated on `CertificateOptions` or `certifiableDomains()`: both rest
        // on `dns_verified_at`, which is set when the domain is added, usually before DNS
        // points here. The reachability stage re-runs DNS verification per name, so a dry
        // run also refreshes that flag.
        if ($application->domains->isEmpty()) {
            throw ValidationException::withMessages([
                'certificate' => [__('errors/certificate.no_certifiable_domains')],
            ]);
        }

        $state = $this->store->start($application->id);

        $this->activityLogger->log('application.certificate_dry_run', $application, [
            'domain' => $application->domain,
        ]);

        DryRunCertificate::dispatch($application->id, Auth::id())
            ->onQueue(app(QueueWorker::class)->priorityQueue());

        return $state;
    }
}
