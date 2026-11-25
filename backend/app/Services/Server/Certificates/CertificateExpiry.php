<?php

namespace App\Services\Server\Certificates;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use Throwable;

/**
 * Reconciles what the panel says about a certificate with what is on disk.
 *
 * Needed because certbot's own timer replaces the file every sixty days without telling the
 * panel, so an `expires_at` captured at issuance would eventually report "expired" on a healthy
 * site.
 *
 * The file is the authority for what should be served, not for what is: the web server holds its
 * own copy in memory from its last start, so a renewal that never reached it leaves the file
 * current and the site presenting the old one. Both are read and any disagreement is recorded.
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
            // The file is unreadable or gone while the vhost still points at it. Recorded, not
            // repaired: reissuing on a schedule would burn rate limit on an unexamined problem.
            if ($certificate->status === CertificateStatus::Active) {
                $certificate->update([
                    'status' => CertificateStatus::Failed,
                    'reason' => 'file_missing',
                ]);

                return true;
            }

            return false;
        }

        // Compared to the second: the change that matters is a renewal, not clock drift.
        if ($certificate->expires_at?->equalTo($expiry)) {
            return $servedChanged;
        }

        $certificate->update(['expires_at' => $expiry]);

        return true;
    }

    /**
     * Ask the web server again right after an issue or upload.
     *
     * Only the served half: the file was just written. Otherwise the old answer stands until the
     * daily refresh and the screen wrongly warns that visitors still see the old certificate.
     * Never throws; a check that cannot run leaves the fields cleared ("not looked yet").
     */
    public function recheckServed(Certificate $certificate): void
    {
        try {
            $this->refreshServed($certificate);
        } catch (Throwable) {
            // The certificate is serving; a failed look is not a failed issue.
        }
    }

    /**
     * Read what the web server presents for this certificate's primary name and record it beside
     * what is on disk. Only for a certificate the panel believes is live.
     *
     * `served_checked_at` is stamped even without an answer, so the screen can tell "checked and
     * agrees" from "never managed to look".
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
