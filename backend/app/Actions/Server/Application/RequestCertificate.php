<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Records the intent to have a certificate and hands the work to a queue.
 *
 * The DNS gate lives here rather than inside the job, because a refusal the
 * user can see immediately is worth more than one they have to poll for — and
 * because the cost of skipping it is real: Let's Encrypt allows five failed
 * authorisations per hostname per hour, so guessing locks the user out of the
 * fix for an hour.
 */
class RequestCertificate
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @throws ValidationException
     */
    public function execute(Application $application, CertificateType $type): Certificate
    {
        $domains = $application->certifiableDomains();

        // A self-signed certificate is the one case where unresolvable names
        // are the *point* — an internal or staging hostname that Let's Encrypt
        // could never validate. Requiring DNS there would refuse the only
        // situation it exists for.
        if ($type === CertificateType::SelfSigned && $domains === []) {
            $domains = $application->serverNames();
        }

        if ($domains === []) {
            throw ValidationException::withMessages([
                'domain' => [__('errors/certificate.no_certifiable_domains')],
            ]);
        }

        $certificate = Certificate::updateOrCreate(
            ['application_id' => $application->id],
            [
                'type' => $type,
                'status' => CertificateStatus::Pending,
                'domains' => $domains,
                'auto_renew' => $type->renewable(),
                'reason' => null,
                'reference' => null,
                // Deliberately not cleared: turning force-HTTPS off because a
                // reissue is in flight would silently change how the site
                // behaves for a reason the user never asked for.
            ],
        );

        $this->activityLogger->log('application.certificate_requested', $application, [
            'domain' => $application->domain,
            'type' => $type->value,
        ]);

        IssueCertificate::dispatch($certificate->id, Auth::id());

        return $certificate->refresh();
    }
}
