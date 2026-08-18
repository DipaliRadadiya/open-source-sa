<?php

namespace App\Services\Server\Certificates;

use App\Enums\CertificateType;
use App\Models\Application;

/**
 * Which kinds of certificate this particular site can actually get, and — when
 * it cannot get one — why, in a sentence.
 *
 * The panel offered Let's Encrypt to every site and let the request fail
 * afterwards, which reads as a broken button on a test or internal site whose
 * name Let's Encrypt was never going to be able to validate. Meanwhile the
 * option that *does* work for those sites, a self-signed certificate, already
 * existed and nothing pointed at it. This is the difference between "SSL is
 * not available here" and "here is the certificate you can have, and here is
 * what it costs you".
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
                // Trusted by browsers with no warning, so it is the right
                // default wherever it is possible at all.
                'recommended' => $blocker === null,
                'renewable' => CertificateType::LetsEncrypt->renewable(),
                'reason' => $blocker,
            ],
            [
                'type' => CertificateType::SelfSigned->value,
                'label' => CertificateType::SelfSigned->label(),
                // Always possible: it is generated on this box and needs
                // nothing from the outside world, which is exactly why it is
                // the answer for a name that does not resolve publicly.
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
     * Why Let's Encrypt is off the table.
     *
     * One reason now, because there is one condition: the name has to resolve
     * to this server. There used to be a second — a wildcard-DNS hostname was
     * refused outright — but such a name resolves here by construction, so it
     * is certifiable like any other and there is nothing left to explain.
     */
    private function blocker(Application $application): string
    {
        return __('certificate.unavailable.dns_unverified');
    }
}
