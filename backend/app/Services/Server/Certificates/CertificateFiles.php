<?php

namespace App\Services\Server\Certificates;

use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Carbon;

/**
 * The two certificate sources that are not certbot: a key pair generated on the
 * box, and one the user pasted in. Plus reading an expiry date back off disk,
 * which is the only honest source for it — the database records what we were
 * told, the file records what the web server will actually present.
 */
class CertificateFiles
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
    ) {}

    /**
     * @return array{certificate: string, private_key: string}
     */
    public function paths(string $domain): array
    {
        $dir = rtrim((string) config('server.certificates.custom_dir'), '/');

        // The domain is validated to a hostname charset long before here, so it
        // cannot introduce a path separator.
        return [
            'certificate' => "{$dir}/{$domain}.crt",
            'private_key' => "{$dir}/{$domain}.key",
        ];
    }

    /**
     * Generate a self-signed certificate.
     *
     * For a name Let's Encrypt cannot reach — a staging site behind a VPN, an
     * internal hostname, anything not publicly resolvable. Every browser warns
     * about it, and that is the honest outcome: the alternative is no TLS at
     * all, and a plaintext admin login is worse than a warning.
     *
     * @param  array<int, string>  $domains
     */
    public function selfSign(array $domains, int $applicationId): ServerOpsResult
    {
        $paths = $this->paths($domains[0]);

        $ensured = $this->ensureDirectory();

        if ($ensured->failed()) {
            return $ensured;
        }

        // Every name goes in subjectAltName, including the first. A certificate
        // whose only name is in the CN is rejected outright by current
        // browsers — CN has not been consulted for host matching for years.
        $altNames = implode(',', array_map(fn (string $d) => 'DNS:'.$d, $domains));

        return $this->serverOps->run([
            'openssl', 'req', '-x509', '-nodes',
            '-newkey', 'rsa:2048',
            '-days', '3650',
            '-keyout', $paths['private_key'],
            '-out', $paths['certificate'],
            '-subj', '/CN='.$domains[0],
            '-addext', 'subjectAltName='.$altNames,
        ], ['feature' => 'certificate', 'op' => 'self_sign', 'application' => $applicationId],
            timeout: (int) config('server.certificates.timeout'),
        );
    }

    /**
     * Write an uploaded certificate and its key to disk.
     *
     * Both go over stdin through ManagedFile, so the private key never appears
     * in a command line and therefore never in `ps` or the server-ops log.
     */
    public function install(string $domain, string $certificate, string $privateKey, ?string $chain, int $applicationId): ServerOpsResult
    {
        $paths = $this->paths($domain);

        $ensured = $this->ensureDirectory();

        if ($ensured->failed()) {
            return $ensured;
        }

        // An intermediate chain that came separately is appended, because the
        // web server is given one file. A certificate served without its
        // intermediates validates in a desktop browser, which carries a cached
        // copy, and fails on phones and API clients that do not — the classic
        // "works for me" TLS bug.
        $bundle = rtrim($certificate)."\n".($chain !== null && trim($chain) !== '' ? rtrim($chain)."\n" : '');

        $written = $this->files->put($paths['certificate'], $bundle, [
            'feature' => 'certificate', 'op' => 'write_certificate', 'application' => $applicationId,
        ]);

        if ($written->failed()) {
            return $written;
        }

        $key = $this->files->put($paths['private_key'], rtrim($privateKey)."\n", [
            'feature' => 'certificate', 'op' => 'write_private_key', 'application' => $applicationId,
        ]);

        if ($key->failed()) {
            return $key;
        }

        return $this->serverOps->run(
            ['chmod', '0600', $paths['private_key']],
            ['feature' => 'certificate', 'op' => 'chmod_private_key', 'application' => $applicationId],
        );
    }

    /**
     * Check that a pasted certificate and key belong together.
     *
     * Worth its own step: a mismatched pair is written happily, fails the
     * config test, and the site goes down over a copy-paste. Comparing the
     * public keys catches it before anything is written.
     */
    public function keyMatchesCertificate(string $certificate, string $privateKey): bool
    {
        $cert = @openssl_x509_read($certificate);
        $key = @openssl_pkey_get_private($privateKey);

        if ($cert === false || $key === false) {
            return false;
        }

        return openssl_x509_check_private_key($cert, $key);
    }

    /**
     * When the certificate on disk actually expires.
     *
     * Read from the file rather than trusted from the request: an uploaded
     * certificate can be anything, including one that expired last month, and
     * a panel that displays what it was told rather than what is true is worse
     * than one that displays nothing.
     */
    public function expiresAt(string $certificatePath): ?Carbon
    {
        $result = $this->serverOps->run(
            ['openssl', 'x509', '-enddate', '-noout', '-in', $certificatePath],
            ['feature' => 'certificate', 'op' => 'read_expiry'],
        );

        if ($result->failed()) {
            return null;
        }

        if (! preg_match('/notAfter=(.+)/', $result->output(), $matches)) {
            return null;
        }

        try {
            return Carbon::parse(trim($matches[1]));
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureDirectory(): ServerOpsResult
    {
        return $this->serverOps->run(
            // 0700: this directory holds private keys, and the web server reads
            // them as root before dropping privileges.
            ['install', '-d', '-m', '0700', rtrim((string) config('server.certificates.custom_dir'), '/')],
            ['feature' => 'certificate', 'op' => 'ensure_cert_dir'],
        );
    }
}
