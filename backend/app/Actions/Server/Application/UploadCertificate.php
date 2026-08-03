<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Certificates\CertificateFiles;

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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application, array $data): Certificate
    {
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

        $certificate = Certificate::updateOrCreate(
            ['application_id' => $application->id],
            [
                'type' => CertificateType::Custom,
                'status' => CertificateStatus::Active,
                // What the certificate says it covers, not what the site
                // serves. An uploaded wildcard may cover names that are not
                // attached yet, and a narrow one may miss names that are.
                'domains' => $this->subjectNames((string) $data['certificate']) ?: [$application->domain],
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

        $this->vhost->execute($application->fresh(['domains', 'certificate']));

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
