<?php

namespace App\Services\Server\Certificates;

use App\Services\Server\ServerOps;
use Illuminate\Support\Carbon;

/**
 * The certificate the web server is actually handing to visitors.
 *
 * Differs from `CertificateFiles::expiresAt()`: renewal replaces the file on disk, but the web
 * server keeps presenting the copy it loaded until it reloads. A real TLS handshake also catches a
 * wrong vhost file, a hand-swapped certificate, a failed reload and an OpenLiteSpeed cache.
 *
 * Done over loopback with SNI, not via the public address, which depends on DNS, the firewall and
 * NAT hairpinning. A site behind Cloudflare reports its own certificate, not Cloudflare's.
 */
class ServedCertificate
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * When the certificate being served for this name expires, or null when it could not be read
     * (nothing listening, TLS refused, no certificate for that name). Null is not "expired".
     */
    public function expiresAt(string $domain): ?Carbon
    {
        $result = $this->serverOps->run(
            [
                'openssl', 's_client',
                // SNI decides which vhost answers; without it the default site's certificate comes back.
                '-servername', $domain,
                '-connect', '127.0.0.1:443',
                // Without this it waits for input and hangs until the timeout.
                '-verify_return_error',
            ],
            ['feature' => 'certificate', 'op' => 'read_served', 'domain' => $domain],
            timeout: 15,
            input: '',
        );

        if ($result->failed()) {
            return null;
        }

        return $this->parseExpiry($result->output());
    }

    /** Pull `notAfter` out of the handshake dump (same format as `x509 -enddate`). */
    private function parseExpiry(string $output): ?Carbon
    {
        if (preg_match('/NotAfter\s*:\s*(.+)/i', $output, $matches) !== 1) {
            return null;
        }

        try {
            return Carbon::parse(trim($matches[1]));
        } catch (\Throwable) {
            return null;
        }
    }
}
