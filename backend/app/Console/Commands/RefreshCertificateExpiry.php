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
 * Keeps the certificate expiry on the SSL screen honest, and keeps renewal reaching the running
 * web server.
 *
 * Certbot's timer renews the file on disk and tells nothing; without this the panel keeps
 * counting down from the issuance date and reports "expired" on a renewed certificate.
 * Daily, because the number it maintains is measured in days. No activity log entry: a daily
 * "re-read a date" would bury real events.
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
     * Without a deploy hook the web server keeps serving the old certificate from memory after
     * certbot renews it. Issuing through the panel installs the hook; certificates adopted by
     * Server Sync or issued before the hook existed do not have it.
     *
     * Written here on the daily tick (one file per machine, cheap) so existing servers get
     * repaired too.
     */
    private function ensureRenewalHook(CertbotClient $certbot, WebServerManager $webServers): void
    {
        // Active only: a pending or failed certificate has nothing on disk for certbot to renew.
        $renewable = Certificate::query()
            ->where('type', CertificateType::LetsEncrypt->value)
            ->where('status', CertificateStatus::Active->value)
            ->exists();

        // Or anything certbot itself renews: the panel's own certificate has no row.
        $renewable = $renewable
            || ! empty(glob(rtrim((string) config('server.certificates.renewal_dir'), '/').'/*.conf'));

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
