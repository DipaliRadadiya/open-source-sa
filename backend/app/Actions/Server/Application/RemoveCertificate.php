<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use Throwable;

/**
 * Takes TLS off a site.
 *
 * Order matters and is not obvious: force-HTTPS is cleared *before* the vhost
 * is rewritten. Rewriting first would leave HTTP redirecting into the TLS
 * rejection vhost — the site would not merely lose HTTPS, it would stop
 * answering usable requests at all.
 */
class RemoveCertificate
{
    public function __construct(
        private CertbotClient $certbot,
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
        private InstallerManager $installers,
        private CertificateFiles $files,
    ) {}

    public function execute(Certificate $certificate): void
    {
        $application = $certificate->application;
        $type = $certificate->type;
        $domains = $certificate->domains ?? [];
        $previousStatus = $certificate->status;
        $previousUrl = $application->fresh(['certificate'])->url();

        try {
            // Change the application's own canonical URL first. `syncUrl()` is
            // not necessarily one operation: WordPress updates two options,
            // while file-backed installers can write successfully and then
            // fail clearing a cache or restarting a process. Its own failure
            // therefore needs the same best-effort rollback as the vhost.
            $this->installers->syncUrl(
                $application->fresh(['systemUser', 'certificate']),
                'http://'.$application->domain,
            );

            // Make the relation non-servable while rendering, but keep the row
            // so the transition and physical cleanup remain retryable.
            $certificate->update(['status' => CertificateStatus::Pending]);

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

        // Cleanup is deliberately after the HTTP vhost is live. From this
        // point onward rollback to HTTPS is unsafe: a deletion command can
        // remove only part of a key pair or lineage before failing. Retaining
        // the pending row gives the same endpoint enough state to retry.
        $cleanup = match ($type) {
            CertificateType::LetsEncrypt => $domains === []
                ? null
                : $this->certbot->revoke($domains[0], $application->id),
            CertificateType::Custom, CertificateType::SelfSigned => $this->files->remove([
                $certificate->certificate_path,
                $certificate->private_key_path,
                $certificate->chain_path,
            ], $application->id),
        };

        if ($cleanup?->failed()) {
            throw new ProvisioningFailedException('remove_certificate', $cleanup->reference);
        }

        $certificate->delete();

        $this->activityLogger->log('application.certificate_removed', $application, [
            'domain' => $application->domain,
        ]);
    }
}
