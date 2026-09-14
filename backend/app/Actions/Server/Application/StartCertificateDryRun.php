<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Jobs\DryRunCertificate;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Certificates\CertificateDryRunStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Starts a rehearsal of a Let's Encrypt issuance.
 *
 * Deliberately has no `force` escape hatch, unlike `RequestCertificate`. Force
 * exists there because a NAT'd box fails the local check while the real
 * challenge, arriving from outside, would succeed — the user needs a way past
 * a check that is wrong about their server. Nothing here needs to be got past:
 * the whole operation is the check.
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
        // Already going. Returned rather than refused, and no second job is
        // dispatched: a double-click is the user asking the same question
        // twice, and two certbot processes on one lineage contend for
        // /var/lib/letsencrypt's lock — one of them would report a failure
        // that says nothing about the domain.
        if ($this->store->isRunning($application->id)) {
            return $this->store->get($application->id) ?? $this->store->start($application->id);
        }

        // An issuance in flight holds the same lock, and the dry run would
        // either block behind it or fail on it. Refused with its own message
        // rather than left to fail obscurely thirty seconds later.
        $certificate = $application->certificate;

        if ($certificate !== null && in_array($certificate->status, [CertificateStatus::Pending, CertificateStatus::Issuing], true)) {
            throw ValidationException::withMessages([
                'certificate' => [__('errors/certificate.dry_run.issue_in_flight')],
            ]);
        }

        // Deliberately *not* gated on `CertificateOptions` or on
        // `certifiableDomains()`. Both rest on `dns_verified_at`, a flag
        // written when the domain was added — which is normally before the
        // user has touched their registrar. Refusing to check a domain
        // because a stale flag says it is not ready is precisely the loop this
        // button exists to break: the answer to "is it ready yet?" cannot be
        // "we will not look".
        //
        // It is also self-correcting. The reachability stage re-runs DNS
        // verification per name, so a dry run on a domain that has since
        // started pointing here updates the flag and un-greys Let's Encrypt in
        // the dialog on its own.
        if ($application->domains->isEmpty()) {
            throw ValidationException::withMessages([
                'certificate' => [__('errors/certificate.no_certifiable_domains')],
            ]);
        }

        $state = $this->store->start($application->id);

        $this->activityLogger->log('application.certificate_dry_run', $application, [
            'domain' => $application->domain,
        ]);

        DryRunCertificate::dispatch($application->id, Auth::id());

        return $state;
    }
}
