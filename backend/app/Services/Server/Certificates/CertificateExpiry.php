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
 * The file is the authority for what *should* be served. It is not the
 * authority for what *is*: the web server holds its own copy in memory from
 * when it last started, so a renewal that never reached it leaves the file
 * current and the site presenting something older. Both are read here, and the
 * disagreement between them is recorded rather than averaged away.
 */
class CertificateExpiry
{
    public function __construct(
        private CertificateFiles $files,
        private ServedCertificate $served,
    ) {}

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
        $servedChanged = $this->refreshServed($certificate);

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
            return $servedChanged;
        }

        $certificate->update(['expires_at' => $expiry]);

        return true;
    }

    /**
     * Read what the web server is presenting for this certificate's primary
     * name, and record it beside what is on disk.
     *
     * Only for a certificate the panel believes is live. Asking about one that
     * is pending or failed would open a TLS connection to learn nothing.
     *
     * `served_checked_at` is stamped whether or not an answer came back, so the
     * screen can tell "checked, and it agrees" from "never managed to look" —
     * a tick for a check that did not run is the failure this is here to stop.
     *
     * @return bool whether anything changed
     */
    private function refreshServed(Certificate $certificate): bool
    {
        if ($certificate->status !== CertificateStatus::Active) {
            return false;
        }

        $domain = $certificate->domains[0] ?? null;

        if (! is_string($domain) || $domain === '') {
            return false;
        }

        $before = $certificate->served_expires_at;
        $servedAt = $this->served->expiresAt($domain);

        $certificate->update([
            'served_expires_at' => $servedAt,
            'served_checked_at' => now(),
        ]);

        return ! (
            ($before === null && $servedAt === null)
            || ($before !== null && $servedAt !== null && $before->equalTo($servedAt))
        );
    }
}
