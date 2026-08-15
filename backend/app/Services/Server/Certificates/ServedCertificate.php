<?php

namespace App\Services\Server\Certificates;

use App\Services\Server\ServerOps;
use Illuminate\Support\Carbon;

/**
 * The certificate the web server is actually handing to visitors.
 *
 * A different question from the one `CertificateFiles::expiresAt()` answers,
 * and the gap between them is a real failure. A certificate lives in two
 * places: the file on disk, and the copy the web server loaded into memory when
 * it last started. Renewal replaces the file and nothing else — without a
 * reload the server goes on presenting the old one, so the panel reads a fresh
 * date off disk and reports eighty-nine days remaining while every visitor gets
 * a browser warning. Reading the file is exactly what hides that.
 *
 * Asked over a real TLS handshake, because nothing short of one answers it. The
 * same read also catches a vhost pointing at the wrong file, a certificate
 * swapped by hand, a reload that failed, and OpenLiteSpeed holding a cached
 * copy — none of which the disk can see either.
 *
 * Over loopback with SNI rather than to the domain across the internet. What is
 * being measured is what *this* server presents for that name: going out to the
 * public address would depend on DNS, on the firewall, and on the box being
 * able to reach its own public IP, which behind NAT it cannot — the failure
 * that already made SSL issuance refuse valid domains. It also means a site
 * behind Cloudflare reports its own certificate rather than Cloudflare's.
 */
class ServedCertificate
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * When the certificate being served for this name expires, or null when the
     * question could not be answered — nothing listening, TLS refused, no
     * certificate for that name.
     *
     * Null is not "expired". A server that is down has not told us anything
     * about its certificate, and reporting that as an expiry would be the same
     * class of invention this class exists to remove.
     */
    public function expiresAt(string $domain): ?Carbon
    {
        $result = $this->serverOps->run(
            [
                'openssl', 's_client',
                // The name matters twice: `-servername` is the SNI header that
                // decides which vhost answers, and without it a server hosting
                // several sites returns whichever certificate is default —
                // reading the wrong site's expiry and reporting it as this one's.
                '-servername', $domain,
                '-connect', '127.0.0.1:443',
                // Without this it waits for input that never comes and the
                // command hangs until the timeout.
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

    /**
     * Pull `notAfter` out of the handshake dump.
     *
     * `s_client` prints the peer certificate in text form; the date is the same
     * format `x509 -enddate` produces, which is why both paths parse alike.
     */
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
