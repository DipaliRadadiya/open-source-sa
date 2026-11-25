<?php

namespace App\Services\Server\Certificates;

use App\Models\Certificate;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The two certificate sources that are not certbot: a key pair generated on the box, and one the
 * user pasted in. Plus reading an expiry date back off disk: the file is what the web server
 * presents, the database only what we were told.
 */
class CertificateFiles
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
    ) {}

    /**
     * Where an application's uploaded or self-signed pair lives.
     *
     * v7's layout: `/etc/ssl/certs/{site}.crt` and `/etc/ssl/private/{site}.key`, named after the
     * application, not its domain, so a server moved from v7 keeps its files. The slug is
     * `[a-z0-9-]` and cannot introduce a path separator.
     *
     * @return array{certificate: string, private_key: string}
     */
    public function paths(string $name): array
    {
        return [
            'certificate' => $this->certsDir()."/{$name}.crt",
            'private_key' => $this->keysDir()."/{$name}.key",
        ];
    }

    /**
     * The first of this application's two paths that already holds something that is not its own
     * certificate, or null when both are free to write.
     *
     * `/etc/ssl/certs` is the server's trust store, not a panel directory: an application named
     * `ca-certificates` would overwrite `ca-certificates.crt` and break HTTPS for curl, apt and git
     * server-wide. So a file is only written over when this application's certificate row already
     * points at it, asked of the disk, not of a list of names (same rule as {@see SlugConflict}).
     * A pair v7 wrote for this application ({@see v7Pair()}) counts as its own.
     *
     * @param  array<int, string|null>  $owned  paths the application's certificate row holds
     * @param  array<int, string>  $domains  the application's domains, to recognise v7's pair
     */
    public function conflict(string $name, array $owned, array $domains = []): ?string
    {
        if ($domains !== [] && ($v7 = $this->v7Pair($name, $domains)) !== null) {
            $owned = [...$owned, $v7['certificate'], $v7['private_key']];
        }

        foreach ($this->paths($name) as $path) {
            if (in_array($path, $owned, true)) {
                continue;
            }

            // Exit 1: nothing there. Anything that exists, a symlink included, is somebody else's.
            $probe = $this->serverOps->run(
                ['test', '-e', $path, '-o', '-L', $path],
                ['feature' => 'certificate', 'op' => 'check_cert_path'],
                expectedExitCodes: [1],
            );

            // Not asked is not "free": a refused sudo also exits 1 and would overwrite the file.
            if ($probe->ok || ! $probe->answered) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Where v7 kept an uploaded certificate's chain on Apache and OpenLiteSpeed: beside the `.crt`
     * (on nginx v7 appended it to the `.crt`). The panel never writes this file.
     */
    public function bundlePath(string $name): string
    {
        return $this->certsDir()."/{$name}_ca_bundle.crt";
    }

    /**
     * The certificate v7 installed for this application, or null.
     *
     * v7 wrote an uploaded pair to the same two paths the panel uses now, so on a moved server they
     * exist with no row behind them. They count as this application's only when both are plain
     * files (no links), the key belongs to the certificate, and the certificate covers one of the
     * application's domains; the trust store or another site's files never qualify.
     *
     * The key is compared by its public half, worked out on the server: the private key is never
     * read into the panel.
     *
     * @param  array<int, string>  $domains
     * @return array{certificate: string, private_key: string, chain: ?string, domains: array<int, string>}|null
     */
    public function v7Pair(string $name, array $domains): ?array
    {
        $paths = $this->paths($name);

        if (! $this->isPlainFile($paths['certificate']) || ! $this->isPlainFile($paths['private_key'])) {
            return null;
        }

        $certificate = $this->serverOps->probe(['cat', '--', $paths['certificate']], ['feature' => 'certificate', 'op' => 'v7_read_certificate']);
        $names = $certificate->ok ? $this->subjectNames($certificate->output()) : [];
        $covering = new Certificate(['domains' => $names]);

        if ($names === [] || ! collect($domains)->contains(fn (string $domain): bool => $covering->covers($domain))) {
            return null;
        }

        $fromCertificate = $this->serverOps->probe(
            ['openssl', 'x509', '-noout', '-pubkey', '-in', $paths['certificate']],
            ['feature' => 'certificate', 'op' => 'v7_certificate_public_key'],
        );
        $fromKey = $this->serverOps->probe(
            ['openssl', 'pkey', '-pubout', '-passin', 'pass:', '-in', $paths['private_key']],
            ['feature' => 'certificate', 'op' => 'v7_key_public_key'],
        );

        if (! $fromCertificate->ok || ! $fromKey->ok || trim($fromCertificate->output()) === ''
            || trim($fromCertificate->output()) !== trim($fromKey->output())) {
            return null;
        }

        $bundle = $this->bundlePath($name);

        return [
            'certificate' => $paths['certificate'],
            'private_key' => $paths['private_key'],
            'chain' => $this->isPlainFile($bundle) ? $bundle : null,
            'domains' => $names,
        ];
    }

    /**
     * Remove v7's separate chain file for this application, after a certificate written here
     * replaced v7's. Only that one exact path, and only a plain file.
     */
    public function removeV7Bundle(string $name, int $applicationId): ?ServerOpsResult
    {
        $bundle = $this->bundlePath($name);

        return $this->isPlainFile($bundle) ? $this->remove([$bundle], $name, $applicationId) : null;
    }

    /** A regular file — `stat` does not follow links, so a link is not one. */
    private function isPlainFile(string $path): bool
    {
        $stat = $this->serverOps->probe(['stat', '-c', '%F', '--', $path], ['feature' => 'certificate', 'op' => 'v7_stat']);

        return $stat->ok && trim($stat->output()) === 'regular file';
    }

    /**
     * @return array{certificate: string, private_key: string}
     */
    public function fallbackPaths(): array
    {
        $directory = rtrim((string) config('server.certificates.custom_dir'), '/');

        // Hidden, panel-reserved filenames, not `paths()` for a domain: a site named
        // `unmatched.invalid` must never overwrite or remove the shared rejection key pair.
        return [
            'certificate' => $directory.'/.panel-tls-reject.crt',
            'private_key' => $directory.'/.panel-tls-reject.key',
        ];
    }

    /**
     * Ensure web servers have a non-application certificate for a TLS reject vhost. Apache and
     * OpenLiteSpeed need a key pair to reject an unmatched HTTPS request; without a reject vhost
     * they serve whichever application is first in configuration order.
     */
    public function ensureFallback(): ServerOpsResult
    {
        // Two sites can provision in parallel; without a lock the certificate of one process could
        // end up beside the key of the other.
        return Cache::lock('tls-fallback-certificate', 30)
            ->block(20, fn () => $this->ensureFallbackLocked());
    }

    private function ensureFallbackLocked(): ServerOpsResult
    {
        $paths = $this->fallbackPaths();
        $existing = $this->serverOps->run(
            ['test', '-s', $paths['certificate'], '-a', '-s', $paths['private_key']],
            ['feature' => 'certificate', 'op' => 'check_tls_fallback'],
        );

        if ($existing->ok) {
            return $existing;
        }

        $ensured = $this->ensureDirectory();

        if ($ensured->failed()) {
            return $ensured;
        }

        $generated = $this->serverOps->run([
            'openssl', 'req', '-x509', '-nodes',
            '-newkey', 'rsa:2048',
            '-days', '3650',
            '-keyout', $paths['private_key'],
            '-out', $paths['certificate'],
            '-subj', '/CN=unmatched.invalid',
            '-addext', 'subjectAltName=DNS:unmatched.invalid',
        ], ['feature' => 'certificate', 'op' => 'create_tls_fallback'],
            timeout: (int) config('server.certificates.timeout'),
        );

        if ($generated->failed()) {
            return $generated;
        }

        return $this->serverOps->run(
            ['chmod', '0600', $paths['private_key']],
            ['feature' => 'certificate', 'op' => 'chmod_tls_fallback_key'],
        );
    }

    /**
     * Generate a self-signed certificate.
     *
     * For a name Let's Encrypt cannot reach (staging behind a VPN, an internal hostname). Browsers
     * warn about it, which beats no TLS at all.
     *
     * @param  array<int, string>  $domains
     */
    public function selfSign(array $domains, string $name, int $applicationId): ServerOpsResult
    {
        $paths = $this->paths($name);

        $ensured = $this->ensureKeyFile($paths['private_key'], $applicationId);

        if ($ensured->failed()) {
            return $ensured;
        }

        // Every name goes in subjectAltName, including the first: current browsers ignore the CN.
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
     * Both go over stdin through ManagedFile, so the private key never appears in a command line,
     * `ps` or the server-ops log.
     */
    public function install(string $name, string $certificate, string $privateKey, ?string $chain, int $applicationId): ServerOpsResult
    {
        $paths = $this->paths($name);

        $ensured = $this->ensureKeyFile($paths['private_key'], $applicationId);

        if ($ensured->failed()) {
            return $ensured;
        }

        // A separate intermediate chain is appended because the web server gets one file; without
        // it the certificate validates in desktop browsers (cached copy) but fails on phones and
        // API clients.
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
     * Remove a panel-managed uploaded or self-signed certificate pair.
     *
     * Stored paths are used, not rebuilt from the current domain, which may have changed. Every
     * path is constrained to the certificate directory before it reaches `rm`; a corrupted row
     * must never become arbitrary-file deletion.
     *
     * @param  array<int, string|null>  $paths
     */
    public function remove(array $paths, string $name, int $applicationId): ServerOpsResult
    {
        $files = $this->managedFiles($paths, $name);

        if ($files === []) {
            return new ServerOpsResult(true, 'certificate-files-already-absent');
        }

        return $this->serverOps->run(
            ['rm', '-f', '--', ...$files],
            ['feature' => 'certificate', 'op' => 'delete_files', 'application' => $applicationId],
        );
    }

    /**
     * The filled paths, each confirmed to be one the panel wrote. Aborts otherwise.
     *
     * Two shapes are ours: a direct child of the panel's own directory, or exactly this
     * application's `{certs_dir}/{name}.crt` / `{keys_dir}/{name}.key`. Never "anything in
     * /etc/ssl/certs": a corrupted row pointing at `ca-certificates.crt` must not break the server.
     *
     * Public so a caller can run the same check *before* changing anything; refusing only at the
     * `rm` would leave a site already off HTTPS.
     *
     * @param  array<int, string|null>  $paths
     * @return array<int, string>
     */
    public function managedFiles(array $paths, string $name): array
    {
        $directory = rtrim((string) config('server.certificates.custom_dir'), '/');
        // Plus v7's chain file for this application, which v7 removed with the pair.
        $own = [...array_values($this->paths($name)), $this->bundlePath($name)];
        $files = array_values(array_unique(array_filter($paths, fn (?string $path): bool => filled($path))));

        foreach ($files as $path) {
            // Direct children only: prefix-only validation would accept `/custom/../../etc/passwd`,
            // so compare the lexical parent (the path need not exist, so a retry is safe).
            abort_unless(dirname($path) === $directory || in_array($path, $own, true), 500);
        }

        return $files;
    }

    /**
     * Check that a pasted certificate and key belong together.
     *
     * Comparing the public keys catches a mismatched copy-paste before anything is written; it
     * would otherwise fail the config test and take the site down.
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
     * Every name the uploaded certificate actually covers.
     *
     * Parsed so the panel can say "this domain is not on your certificate" before a visitor's
     * browser does.
     *
     * @return array<int, string>
     */
    public function subjectNames(string $pem): array
    {
        $parsed = @openssl_x509_parse($pem);

        if ($parsed === false) {
            return [];
        }

        $names = [];

        if (isset($parsed['subject']['CN'])) {
            $names[] = strtolower((string) $parsed['subject']['CN']);
        }

        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * When the certificate on disk actually expires.
     *
     * Read from the file, not trusted from the request: an uploaded certificate can be anything,
     * including one already expired.
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
            // 0700: this directory holds private keys; the web server reads them as root.
            ['install', '-d', '-m', '0700', rtrim((string) config('server.certificates.custom_dir'), '/')],
            ['feature' => 'certificate', 'op' => 'ensure_cert_dir'],
        );
    }

    /**
     * Create the key file 0600 before anything is written into it.
     *
     * `tee` and `openssl -keyout` keep the mode of an existing file, so the key is never readable by
     * others, even between writing and a chmod. The two directories are the distro's and are never
     * created or re-moded here: `install -d -m` on /etc/ssl/certs would chmod the trust store.
     */
    private function ensureKeyFile(string $path, int $applicationId): ServerOpsResult
    {
        return $this->serverOps->run(
            ['install', '-m', '0600', '/dev/null', $path],
            ['feature' => 'certificate', 'op' => 'create_private_key', 'application' => $applicationId],
        );
    }

    private function certsDir(): string
    {
        return rtrim((string) config('server.certificates.certs_dir', '/etc/ssl/certs'), '/');
    }

    private function keysDir(): string
    {
        return rtrim((string) config('server.certificates.keys_dir', '/etc/ssl/private'), '/');
    }
}
