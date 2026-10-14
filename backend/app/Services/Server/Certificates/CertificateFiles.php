<?php

namespace App\Services\Server\Certificates;

use App\Models\Certificate;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
     * Where an application's uploaded or self-signed pair lives.
     *
     * v7's layout (operator, 2026-10-08): `/etc/ssl/certs/{site}.crt` and
     * `/etc/ssl/private/{site}.key`, named after the application rather than
     * its domain — so a server moved from v7 keeps its files, and changing the
     * primary domain no longer moves them. The slug is `[a-z0-9-]` and cannot
     * introduce a path separator.
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
     * The first of this application's two paths that already holds something
     * that is not its own certificate, or null when both are free to write.
     *
     * 🔴 `/etc/ssl/certs` is the server's trust store, not a panel directory:
     * an application named `ca-certificates` would otherwise overwrite
     * `ca-certificates.crt` and break HTTPS for curl, apt and git server-wide,
     * and one named `ssl-cert-snakeoil` would replace the distro's key. So a
     * file is only ever written over when it is the one this application's
     * certificate row already points at — asked of the disk, not of a list of
     * names someone thought of (the same rule as {@see SlugConflict}).
     *
     * A pair v7 wrote for this same application ({@see v7Pair()}) is its own
     * too: on a server moved from v7 it is exactly what the user is replacing.
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

            // Exit 1: nothing there — the answer we want. Anything that exists,
            // a symlink included, is somebody else's.
            $probe = $this->serverOps->run(
                ['test', '-e', $path, '-o', '-L', $path],
                ['feature' => 'certificate', 'op' => 'check_cert_path'],
                expectedExitCodes: [1],
            );

            // Not asked is not "free": a refused sudo also exits 1, and a
            // guard that reads that as "nothing there" overwrites the file.
            if ($probe->ok || ! $probe->answered) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Where v7 kept an uploaded certificate's chain on Apache and
     * OpenLiteSpeed: beside the `.crt`, not inside it (on nginx it appended
     * the chain to the `.crt` instead). The panel never writes this file.
     */
    public function bundlePath(string $name): string
    {
        return $this->certsDir()."/{$name}_ca_bundle.crt";
    }

    /**
     * The certificate v7 installed for this application, or null.
     *
     * v7 wrote an uploaded pair to the same two paths the panel uses now
     * (agent ssl.go, customSSLCertificate), so on a server moved from v7 they
     * are already there with no row behind them. They count as this
     * application's only when all of it holds: both are plain files (no
     * links), the key belongs to the certificate, and the certificate covers
     * one of the application's domains — so the trust store, the distro's
     * snakeoil pair or another site's files never qualify.
     *
     * The key is compared by its public half, worked out on the server: the
     * private key itself is never read into the panel.
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
     * Remove v7's separate chain file for this application, when there is
     * one — after a certificate written here replaced v7's, whose chain it
     * was. Only ever that one exact path, and only a plain file.
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

        // Hidden, panel-reserved filenames rather than `paths()` for a domain:
        // `unmatched.invalid` is a legitimate internal hostname and must never
        // overwrite — or remove — the shared rejection key pair.
        return [
            'certificate' => $directory.'/.panel-tls-reject.crt',
            'private_key' => $directory.'/.panel-tls-reject.key',
        ];
    }

    /**
     * Ensure web servers have a non-application certificate for a TLS reject
     * vhost. Apache and OpenLiteSpeed require a key pair before they can reject
     * an unmatched HTTPS request; without a reject vhost they serve whichever
     * real application happens to be first in configuration order.
     */
    public function ensureFallback(): ServerOpsResult
    {
        // Two sites can provision in parallel. Generating directly into one
        // shared pair without a lock can leave the certificate from one
        // process beside the key from the other.
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
     * For a name Let's Encrypt cannot reach — a staging site behind a VPN, an
     * internal hostname, anything not publicly resolvable. Every browser warns
     * about it, and that is the honest outcome: the alternative is no TLS at
     * all, and a plaintext admin login is worse than a warning.
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
    public function install(string $name, string $certificate, string $privateKey, ?string $chain, int $applicationId): ServerOpsResult
    {
        $paths = $this->paths($name);

        $ensured = $this->ensureKeyFile($paths['private_key'], $applicationId);

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
     * Remove a panel-managed uploaded or self-signed certificate pair.
     *
     * Stored paths are used rather than rebuilding them from the application's
     * current domain: the primary domain may have changed since the files were
     * created. Every path is constrained to the private certificate directory
     * before it reaches `rm`; a corrupted row must never become arbitrary-file
     * deletion.
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
     * The filled paths, each confirmed to be one the panel wrote. Aborts
     * otherwise.
     *
     * Two shapes are ours: a direct child of the panel's own directory (every
     * certificate made before 2026-10-08), or exactly this application's own
     * `{certs_dir}/{name}.crt` / `{keys_dir}/{name}.key`. Never "anything in
     * /etc/ssl/certs" — that is the server's trust store, and a corrupted row
     * pointing at `ca-certificates.crt` must not become a broken server.
     *
     * Public so a caller can run the same check *before* it changes anything:
     * refusing only at the `rm` would leave a site already taken off HTTPS
     * with a row that fails the same way on every retry.
     *
     * @param  array<int, string|null>  $paths
     * @return array<int, string>
     */
    public function managedFiles(array $paths, string $name): array
    {
        $directory = rtrim((string) config('server.certificates.custom_dir'), '/');
        // Plus v7's chain file for this application, which v7's own uninstall
        // removed with the pair.
        $own = [...array_values($this->paths($name)), $this->bundlePath($name)];
        $files = array_values(array_unique(array_filter($paths, fn (?string $path): bool => filled($path))));

        foreach ($files as $path) {
            // These files are always direct children. Prefix-only validation
            // would accept `/custom/../../etc/passwd`, so compare the lexical
            // parent too; the path need not exist for a retry-safe removal.
            abort_unless(dirname($path) === $directory || in_array($path, $own, true), 500);
        }

        return $files;
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
     * Every name the uploaded certificate actually covers.
     *
     * Parsed rather than assumed so the panel can say "this domain is not on
     * your certificate" — the failure that otherwise appears only in the
     * visitor's browser, on a site whose panel says everything is fine.
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

    /**
     * Create the key file 0600 before anything is written into it.
     *
     * `tee` and `openssl -keyout` both keep the mode of a file that already
     * exists, so the key is never readable by anyone else — not even for the
     * moment between writing it and a chmod. The two directories are the
     * distro's own and are never created or re-moded here: `install -d -m`
     * on an existing /etc/ssl/certs would chmod the trust store.
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
