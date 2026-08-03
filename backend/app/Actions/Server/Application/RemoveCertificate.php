<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Certificates\CertbotClient;

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
    ) {}

    public function execute(Certificate $certificate): void
    {
        $application = $certificate->application;
        $type = $certificate->type;
        $domains = $certificate->domains ?? [];

        $certificate->delete();

        // Rewrite without the TLS block. The certificate row is already gone,
        // so `force_https` is gone with it and the plain HTTP block comes back
        // rather than redirecting into nothing.
        $this->vhost->execute($application->fresh(['domains', 'certificate']));

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
