<?php

namespace App\Services\Server\Certificates;

use App\Enums\CertificateStatus;
use App\Models\Certificate;

/**
 * Reconciles what the panel says about a certificate with what is on disk.
 *
 * Needed because renewal happens somewhere the panel is not. certbot's own
 * timer replaces the file every sixty days without telling anyone, so an
 * `expires_at` captured at issuance is right for two months and then confidently
 * wrong — the screen counts down to zero and reports "expired" on a site that is
 * perfectly healthy. A panel that lies about a working site is worse than one
 * that says nothing.
 *
 * The file is the authority throughout. The database records what we were told;
 * the certificate on disk is what the web server actually presents.
 */
class CertificateExpiry
{
    public function __construct(private CertificateFiles $files) {}

    /**
     * Re-read every active certificate.
     *
     * @return int how many rows changed
     */
    public function refreshAll(): int
    {
        $changed = 0;

        Certificate::query()
            ->where('status', CertificateStatus::Active->value)
            ->whereNotNull('certificate_path')
            ->cursor()
            ->each(function (Certificate $certificate) use (&$changed) {
                if ($this->refresh($certificate)) {
                    $changed++;
                }
            });

        return $changed;
    }

    /**
     * @return bool whether anything about this certificate changed
     */
    public function refresh(Certificate $certificate): bool
    {
        $expiry = $this->files->expiresAt((string) $certificate->certificate_path);

        if ($expiry === null) {
            // The file is unreadable or gone, and the vhost is still pointing
            // at it — so the site is serving a certificate that is not there.
            // Recorded rather than repaired: reissuing on a schedule would
            // spend rate limit on a problem nobody has looked at yet, and the
            // whole point of this class is to report the truth, not act on it.
            if ($certificate->status === CertificateStatus::Active) {
                $certificate->update([
                    'status' => CertificateStatus::Failed,
                    'reason' => 'file_missing',
                ]);

                return true;
            }

            return false;
        }

        // Compared to the second, because the interesting change is a renewal
        // that moved the date sixty days out — not a clock drifting.
        if ($certificate->expires_at?->equalTo($expiry)) {
            return false;
        }

        $certificate->update(['expires_at' => $expiry]);

        return true;
    }
}
