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
 * Order matters: force-HTTPS is cleared *before* the vhost is rewritten, or HTTP would
 * redirect into the TLS rejection vhost and the site would stop answering at all.
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
        $previousStatus = $certificate->status;
        $previousUrl = $application->fresh(['certificate'])->url();

        // The pair only: an uploaded chain is bundled into the `.crt`, and a stale
        // `chain_path` can point into /etc/letsencrypt, which the directory guard refuses.
        // Checked here, before anything changes.
        $files = $type === CertificateType::LetsEncrypt
            ? []
            : $this->files->managedFiles([
                $certificate->certificate_path,
                $certificate->private_key_path,
                // v7's separate chain file, which its own uninstall removed too.
                $certificate->servesSeparateChain() ? $certificate->chain_path : null,
            ], $application->slug);

        try {
            // Change the application's own canonical URL first. `syncUrl()` can half-succeed
            // (write, then fail on a cache or restart), so it needs the same rollback as the vhost.
            $this->installers->syncUrl(
                $application->fresh(['systemUser', 'certificate']),
                'http://'.$application->domain,
            );

            // Non-servable while rendering, but keep the row so cleanup stays retryable.
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

        // Cleanup runs after the HTTP vhost is live. Rolling back to HTTPS is unsafe from
        // here (a delete can remove only part of a key pair), so the pending row stays for a retry.
        $cleanup = match ($type) {
            CertificateType::LetsEncrypt => ($lineage = $certificate->lineageName()) === null
                ? null
                : $this->certbot->revoke($lineage, $application->id),
            CertificateType::Custom, CertificateType::SelfSigned => $this->files->remove($files, $application->slug, $application->id),
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
