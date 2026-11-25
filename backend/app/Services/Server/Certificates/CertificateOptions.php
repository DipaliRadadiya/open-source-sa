<?php

namespace App\Services\Server\Certificates;

use App\Enums\CertificateType;
use App\Models\Application;

/**
 * Which kinds of certificate this site can actually get, and why not when it cannot.
 *
 * Offering Let's Encrypt to every site made it fail afterwards on names it can never validate;
 * this points those sites at a self-signed certificate instead.
 */
class CertificateOptions
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(Application $application): array
    {
        $application->loadMissing('domains');

        $certifiable = $application->certifiableDomains();
        $blocker = $certifiable === [] ? $this->blocker($application) : null;

        return [
            [
                'type' => CertificateType::LetsEncrypt->value,
                'label' => CertificateType::LetsEncrypt->label(),
                'available' => $blocker === null,
                // Trusted by browsers with no warning: the default wherever possible.
                'recommended' => $blocker === null,
                'renewable' => CertificateType::LetsEncrypt->renewable(),
                'reason' => $blocker,
            ],
            [
                'type' => CertificateType::SelfSigned->value,
                'label' => CertificateType::SelfSigned->label(),
                // Always possible: generated locally, so it suits a name that does not
                // resolve publicly.
                'available' => true,
                'recommended' => $blocker !== null,
                'renewable' => CertificateType::SelfSigned->renewable(),
                'reason' => __('certificate.unavailable.self_signed_warning'),
            ],
            [
                'type' => CertificateType::Custom->value,
                'label' => CertificateType::Custom->label(),
                'available' => true,
                'recommended' => false,
                'renewable' => CertificateType::Custom->renewable(),
                'reason' => null,
            ],
        ];
    }

    /**
     * Why Let's Encrypt is off the table: the name has to resolve to this server.
     */
    private function blocker(Application $application): string
    {
        return __('certificate.unavailable.dns_unverified');
    }
}
