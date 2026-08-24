<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
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
        $domains = $this->subjectNames((string) $data['certificate']) ?: [$application->domain];
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

        $this->activityLogger->log('application.certificate_uploaded', $application, [
            'domain' => $application->domain,
        ]);

        return $certificate->refresh();
    }

    /**
     * Every name the uploaded certificate actually covers.
     *
     * Parsed rather than assumed so the panel can say "this domain is not on
     * your certificate" — the failure that otherwise appears only in the
     * visitor's browser, on a site whose panel says everything is fine.
     *
     * @return array<int, string>
     */
    private function subjectNames(string $pem): array
    {
        $parsed = @openssl_x509_parse($pem);

        if ($parsed === false) {
            return [];
        }

        $names = [];

        if (isset($parsed['subject']['CN'])) {
            $names[] = strtolower((string) $parsed['subject']['CN']);
        }

        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}
