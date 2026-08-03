<?php

namespace App\Console\Commands;

use App\Services\Server\Certificates\CertificateExpiry;
use Illuminate\Console\Command;

/**
 * Keeps the certificate expiry on the SSL screen honest.
 *
 * Renewal is certbot's timer, not ours: it swaps the file on disk roughly every
 * sixty days and tells nothing. Without this the panel counts down from the date
 * captured at issuance and eventually reports "expired" on a site whose
 * certificate was renewed correctly weeks earlier.
 *
 * Daily, because the number it maintains is measured in days.
 *
 * No activity log entry: "we re-read a date" is not an event anybody needs to
 * find later, and on a daily tick across every site it would bury the ones that
 * are.
 */
class RefreshCertificateExpiry extends Command
{
    protected $signature = 'certificates:refresh-expiry';

    protected $description = 'Re-read the expiry date of every active certificate from disk.';

    public function handle(CertificateExpiry $expiry): int
    {
        $this->info("Updated {$expiry->refreshAll()} certificates.");

        return self::SUCCESS;
    }
}
