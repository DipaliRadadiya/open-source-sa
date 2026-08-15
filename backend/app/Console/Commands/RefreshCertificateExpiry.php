<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateExpiry;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Console\Command;

/**
 * Keeps the certificate expiry on the SSL screen honest, and keeps renewal
 * actually reaching the running web server.
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

    public function handle(
        CertificateExpiry $expiry,
        CertbotClient $certbot,
        WebServerManager $webServers,
    ): int {
        $this->ensureRenewalHook($certbot, $webServers);

        $this->info("Updated {$expiry->refreshAll()} certificates.");

        return self::SUCCESS;
    }

    /**
     * Make sure a renewal actually reaches the running web server.
     *
     * certbot writes the new certificate and stops there. Without a deploy hook
     * the web server goes on serving the old one out of memory until something
     * unrelated reloads it, so the site shows an expired certificate while the
     * files on disk are current — a failure that surfaces weeks after the thing
     * that caused it, on a site nobody touched.
     *
     * Issuing a certificate through the panel installs the hook. Two routes
     * never did: a certificate **adopted from a migrated server** by Server
     * Sync, which is the case this panel exists to serve, and one issued before
     * the hook existed. Both renew silently and stop being served.
     *
     * Written here rather than at adoption because it is one file for the whole
     * machine, `install -d` and a write are cheap, and doing it on a daily tick
     * repairs every server that already has the problem instead of only the
     * ones adopted from now on.
     */
    private function ensureRenewalHook(CertbotClient $certbot, WebServerManager $webServers): void
    {
        // Active only, matching what the expiry refresh looks at. A pending or
        // failed certificate has nothing on disk for certbot to renew, so
        // writing a hook for it would be a privileged command run for nothing.
        $renewable = Certificate::query()
            ->where('type', CertificateType::LetsEncrypt->value)
            ->where('status', CertificateStatus::Active->value)
            ->exists();

        if (! $renewable) {
            return;
        }

        $result = $certbot->ensureRenewalHook(
            implode(' ', $webServers->driver()->reloadCommandForHook()),
        );

        if ($result->failed()) {
            $this->warn('Could not write the certificate renewal hook.');
        }
    }
}
