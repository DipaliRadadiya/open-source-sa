<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
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

        $passed = array_values(array_map(
            fn (array $result) => $result['domain'],
            array_filter($this->reachability->checkAll($candidates), fn (array $result) => $result['ok']),
        ));

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
        IssueCertificate::dispatch($certificate->id, null);

        return $certificate;
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
