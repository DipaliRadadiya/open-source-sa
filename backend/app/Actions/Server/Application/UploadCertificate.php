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
use App\Services\Server\Certificates\CertificateExpiry;
use App\Services\Server\Certificates\CertificateFiles;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Installs a certificate the user pasted in.
 *
 * Synchronous, unlike issuance: writing two files and reloading takes milliseconds.
 *
 * The pair is checked before anything is written: the filesystem accepts a mismatched certificate
 * and key, which then fails the config test and takes the site down.
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

        // Before a byte is written: the pair goes into /etc/ssl (v7's layout), where a file this
        // application does not own belongs to someone else (trust store, snakeoil key).
        $taken = $this->files->conflict($application->slug, [
            $previousCertificate['certificate_path'] ?? null,
            $previousCertificate['private_key_path'] ?? null,
        ], $application->domainNames());

        if ($taken !== null) {
            throw ValidationException::withMessages([
                'certificate' => __('certificate.file_taken', ['path' => $taken]),
            ]);
        }

        $result = $this->files->install(
            $application->slug,
            (string) $data['certificate'],
            (string) $data['private_key'],
            $data['chain'] ?? null,
            $application->id,
        );

        if ($result->failed()) {
            throw new ProvisioningFailedException('write_certificate', $result->reference);
        }

        $paths = $this->files->paths($application->slug);
        $domains = $this->files->subjectNames((string) $data['certificate']) ?: [$application->domain];
        $candidate = new Certificate(['domains' => $domains]);
        $targetUrl = ($candidate->covers((string) $application->domain) ? 'https://' : 'http://').$application->domain;
        $certificate = null;

        try {
            // Reconcile first, so a failure cannot leave the vhost advertising a scheme the app
            // could not adopt.
            $this->installers->syncUrl(
                $application->fresh(['domains', 'certificate', 'systemUser']),
                $targetUrl,
            );

            $certificate = Certificate::updateOrCreate(
                ['application_id' => $application->id],
                [
                    'type' => CertificateType::Custom,
                    'status' => CertificateStatus::Active,
                    // What the certificate covers, not what the site serves.
                    'domains' => $domains,
                    'certificate_path' => $paths['certificate'],
                    'private_key_path' => $paths['private_key'],
                    // The chain is bundled into the `.crt`; an old Let's Encrypt path would point
                    // into an unrelated lineage.
                    'chain_path' => null,
                    'uploaded_private_key' => (string) $data['private_key'],
                    // Nothing can renew an uploaded certificate.
                    'auto_renew' => false,
                    'issued_at' => now(),
                    // Read back off the file: an upload may already be expired.
                    'expires_at' => $this->files->expiresAt($paths['certificate']),
                    'reason' => null,
                    'reference' => null,
                    // The last look was at the certificate this replaces.
                    'served_expires_at' => null,
                    'served_checked_at' => null,
                ],
            );

            $application = $application->fresh(['domains', 'certificate', 'systemUser']);
            $this->vhost->execute($application);
            app(CertificateExpiry::class)->recheckServed($certificate->fresh());
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

        $this->removeReplaced($previousCertificate, $paths, $application);

        $this->activityLogger->log('application.certificate_uploaded', $application, [
            'domain' => $application->domain,
        ]);

        return $certificate->refresh();
    }

    /**
     * Whatever this upload replaced, once the upload is serving.
     *
     * A leftover Let's Encrypt lineage would renew forever for an unused certificate, and an older
     * pair at other paths is an orphaned private key. Best effort: the new certificate is already
     * live, so a tidy-up failure must not report the upload as failed.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array{certificate: string, private_key: string}  $paths
     */
    private function removeReplaced(?array $previous, array $paths, Application $application): void
    {
        // The chain is bundled into the new `.crt`, so v7's separate chain file is stale; remove it
        // as v7's uninstall did.
        try {
            $this->files->removeV7Bundle($application->slug, $application->id);
        } catch (Throwable) {
            // Never fail a live upload over the old certificate.
        }

        if ($previous === null) {
            return;
        }

        try {
            if ($previous['type'] === CertificateType::LetsEncrypt) {
                // The lineage the files are in; never re-derived from the domains, which can name
                // the wrong one.
                $lineage = (new Certificate([
                    'type' => CertificateType::LetsEncrypt,
                    'certificate_path' => $previous['certificate_path'] ?? null,
                ]))->setRelation('application', $application)->lineageName();

                if ($lineage !== null) {
                    $this->certbot->revoke($lineage, $application->id);
                }

                return;
            }

            $stale = array_values(array_diff(
                array_filter([$previous['certificate_path'], $previous['private_key_path']]),
                [$paths['certificate'], $paths['private_key']],
            ));

            if ($stale !== []) {
                $this->files->remove($stale, $application->slug, $application->id);
            }
        } catch (Throwable) {
            // See above: never fail a live upload over the old certificate.
        }
    }
}
