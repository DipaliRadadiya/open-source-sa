<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use Throwable;

/**
 * Installs a certificate the user pasted in.
 *
 * Synchronous, unlike issuance: there is nothing to wait for. Writing two files
 * and reloading takes milliseconds, and a queued job would only add a spinner
 * to something already finished.
 *
 * The pair is checked before anything is written. A mismatched certificate and
 * key are accepted happily by the filesystem, fail the config test, and take
 * the site down over a copy-paste — this catches it while nothing has changed.
 */
class UploadCertificate
{
    public function __construct(
        private CertificateFiles $files,
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
        private InstallerManager $installers,
        private CertbotClient $certbot,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application, array $data): Certificate
    {
        $previousUrl = $application->fresh(['certificate'])->url();
        $previousCertificate = $application->certificate?->only([
            'type', 'status', 'domains', 'certificate_path', 'private_key_path',
            'chain_path', 'uploaded_private_key', 'force_https', 'auto_renew',
            'issued_at', 'expires_at', 'reason', 'reference',
        ]);

        $result = $this->files->install(
            $application->domain,
            (string) $data['certificate'],
            (string) $data['private_key'],
            $data['chain'] ?? null,
            $application->id,
        );

        if ($result->failed()) {
            throw new ProvisioningFailedException('write_certificate', $result->reference);
        }

        $paths = $this->files->paths($application->domain);
        $domains = $this->files->subjectNames((string) $data['certificate']) ?: [$application->domain];
        $candidate = new Certificate(['domains' => $domains]);
        $targetUrl = ($candidate->covers((string) $application->domain) ? 'https://' : 'http://').$application->domain;
        $certificate = null;

        try {
            // Reconcile first: a failure must not make the vhost advertise a
            // canonical scheme the application itself could not adopt.
            $this->installers->syncUrl(
                $application->fresh(['domains', 'certificate', 'systemUser']),
                $targetUrl,
            );

            $certificate = Certificate::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'type' => CertificateType::Custom,
                    'status' => CertificateStatus::Active,
                    // What the certificate says it covers, not what the site
                    // serves. An uploaded wildcard may cover names that are not
                    // attached yet, and a narrow one may miss names that are.
                    'domains' => $domains,
                    'certificate_path' => $paths['certificate'],
                    'private_key_path' => $paths['private_key'],
                    // The chain is bundled into the `.crt` above. A path left
                    // over from a previous Let's Encrypt certificate points
                    // into a lineage this certificate has nothing to do with.
                    'chain_path' => null,
                    'uploaded_private_key' => (string) $data['private_key'],
                    // Nothing can renew an uploaded certificate. Saying otherwise
                    // would be a promise the panel cannot keep.
                    'auto_renew' => false,
                    'issued_at' => now(),
                    // Read back off the file. An uploaded certificate can be
                    // anything, including one that expired last month, and a panel
                    // that shows what it was told rather than what is true is worse
                    // than one that shows nothing.
                    'expires_at' => $this->files->expiresAt($paths['certificate']),
                    'reason' => null,
                    'reference' => null,
                ],
            );

            $application = $application->fresh(['domains', 'certificate', 'systemUser']);
            $this->vhost->execute($application);
        } catch (Throwable $exception) {
            if ($previousCertificate === null) {
                $certificate?->delete();
            } else {
                Certificate::updateOrCreate(
                    ['application_id' => $application->id],
                    $previousCertificate,
                );
            }

            try {
                $restored = $application->fresh(['domains', 'certificate', 'systemUser']);
                $this->installers->syncUrl($restored, $previousUrl);
                $this->vhost->execute($restored);
            } catch (Throwable) {
                // Preserve the upload transition's original failure reference.
            }

            throw $exception;
        }

        $this->removeReplaced($previousCertificate, $paths, $application->id);

        $this->activityLogger->log('application.certificate_uploaded', $application, [
            'domain' => $application->domain,
        ]);

        return $certificate->refresh();
    }

    /**
     * Whatever this upload replaced, once the upload is serving.
     *
     * A Let's Encrypt lineage left behind renews itself forever for a
     * certificate nothing uses; an older uploaded or self-signed pair at other
     * paths (the primary domain changed since) is a private key with nothing
     * to belong to. Best effort: the new certificate is already live, and
     * failing to tidy up the old one must not report the upload as failed.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array{certificate: string, private_key: string}  $paths
     */
    private function removeReplaced(?array $previous, array $paths, int $applicationId): void
    {
        if ($previous === null) {
            return;
        }

        try {
            if ($previous['type'] === CertificateType::LetsEncrypt) {
                $lineage = $previous['domains'][0] ?? null;

                if ($lineage !== null) {
                    $this->certbot->revoke($lineage, $applicationId);
                }

                return;
            }

            $stale = array_values(array_diff(
                array_filter([$previous['certificate_path'], $previous['private_key_path']]),
                [$paths['certificate'], $paths['private_key']],
            ));

            if ($stale !== []) {
                $this->files->remove($stale, $applicationId);
            }
        } catch (Throwable) {
            // See above: never fail a live upload over the old certificate.
        }
    }
}
