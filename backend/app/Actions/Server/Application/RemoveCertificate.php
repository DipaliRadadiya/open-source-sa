<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Certificates\CertbotClient;
use Throwable;

/**
 * Takes TLS off a site.
 *
 * Order matters and is not obvious: force-HTTPS is cleared *before* the vhost
 * is rewritten. Rewriting first would produce a config that redirects every
 * visitor to a port no longer listening — the site would not merely lose HTTPS,
 * it would stop answering at all.
 */
class RemoveCertificate
{
    public function __construct(
        private CertbotClient $certbot,
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
        private InstallerManager $installers,
    ) {}

    public function execute(Certificate $certificate): void
    {
        $application = $certificate->application;
        $type = $certificate->type;
        $domains = $certificate->domains ?? [];
        $previousStatus = $certificate->status;
        $previousUrl = $application->fresh(['certificate'])->url();

        // Change the application's own canonical URL first. If that cannot be
        // done, leave the working certificate and vhost untouched.
        $this->installers->syncUrl(
            $application->fresh(['systemUser', 'certificate']),
            'http://'.$application->domain,
        );

        // Make the relation non-servable while rendering, but keep the row so
        // the whole transition can be rolled back if the vhost rejects it.
        $certificate->update(['status' => CertificateStatus::Pending]);

        try {
            $this->vhost->execute($application->fresh(['domains', 'certificate']));
        } catch (Throwable $exception) {
            $certificate->update(['status' => $previousStatus]);

            try {
                $restored = $application->fresh(['domains', 'certificate', 'systemUser']);
                $this->installers->syncUrl($restored, $previousUrl);
                $this->vhost->execute($restored);
            } catch (Throwable) {
                // Preserve the transition's original failure reference.
            }

            throw $exception;
        }

        $certificate->delete();

        // Stop certbot renewing something nothing is serving. Left behind, the
        // renewal keeps running forever, keeps spending rate limit, and
        // eventually emails the user about a site they removed.
        if ($type === CertificateType::LetsEncrypt && $domains !== []) {
            $this->certbot->revoke($domains[0], $application->id);
        }

        $this->activityLogger->log('application.certificate_removed', $application, [
            'domain' => $application->domain,
        ]);
    }
}
