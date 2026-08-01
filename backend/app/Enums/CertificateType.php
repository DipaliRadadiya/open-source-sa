<?php

namespace App\Enums;

/**
 * Where a certificate came from, which decides what can be done to it.
 */
enum CertificateType: string
{
    /** Issued by certbot over the ACME HTTP-01 challenge. Renews itself. */
    case LetsEncrypt = 'letsencrypt';

    /** Uploaded by the user. Nothing can renew it; it expires and stops. */
    case Custom = 'custom';

    /**
     * Generated on the box. Every browser will warn about it, so it exists for
     * one purpose: giving a staging or internal site working TLS when the name
     * is not publicly resolvable and Let's Encrypt therefore cannot validate
     * it at all.
     */
    case SelfSigned = 'self_signed';

    public function label(): string
    {
        return __('certificate.type.'.$this->value);
    }

    /** Whether anything can renew this without the user acting. */
    public function renewable(): bool
    {
        return $this === self::LetsEncrypt;
    }
}
