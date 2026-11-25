<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fetches an addon binary Central offers and proves it is the one Central built, before
 * anything is installed from it.
 *
 *   - SHA-256 from Central: not a truncated or swapped download;
 *   - Ed25519 signature against a key configured on this server: Central chose this file, not
 *     someone who merely holds the central token. Without a key nothing is installed;
 *   - ELF magic: a Linux executable, not an error page that happens to have been signed.
 *
 * Same pattern as IonCubeLoader: TLS only, bounded time and size, nothing trusted beyond the bytes.
 */
class AddonPackage
{
    private const ELF_MAGIC = "\x7fELF";

    /** ELF e_machine for x86-64, the only CPU the addons are built for. */
    private const ELF_X86_64 = 0x3E;

    /** The CPU names `uname -m` gives an x86-64 server. */
    private const X86_64 = ['x86_64', 'amd64'];

    /**
     * The configured public key, raw, or null when there is none usable.
     * A key that is not 32 bytes of base64 is no key.
     */
    public static function signingKey(): ?string
    {
        $key = base64_decode((string) config('server.addons.signing_public_key'), true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    /** Whether this server's CPU is one the addons are built for. */
    public static function hostSupported(): bool
    {
        $machine = (string) (config('server.addons.host_machine') ?: php_uname('m'));

        return in_array(strtolower($machine), self::X86_64, true);
    }

    /** False only in test mode (config `server.addons.require_signature`). */
    public static function signatureRequired(): bool
    {
        return (bool) config('server.addons.require_signature');
    }

    /**
     * Download to `$to` and verify it. The caller deletes `$to`, verified or not.
     *
     * @throws AddonException
     */
    public function fetch(AddonCli $addon, string $url, string $sha256, ?string $signature, string $to): void
    {
        // x86-64 only: on an ARM server the file would pass every check below and then not run.
        // Refused first, under the code Central reads as "unsupported CPU".
        if (! self::hostSupported()) {
            throw AddonException::of('no_build_for_architecture', $addon->label());
        }

        // Before the download: no key means no install. Test mode excepted.
        $key = self::signingKey();

        if ($key === null && self::signatureRequired()) {
            throw AddonException::of('addon_signing_key_missing', $addon->label());
        }

        $this->download($addon, $url, $to);

        if (! hash_equals(strtolower($sha256), (string) hash_file('sha256', $to))) {
            throw AddonException::of('addon_checksum_mismatch', $addon->label());
        }

        // Test mode skips the signature only when there is nothing to check (no signature or no key).
        if (self::signatureRequired() || ($key !== null && $signature !== null && $signature !== '')) {
            $decoded = base64_decode((string) $signature, true);

            // Ed25519 signs the message itself, so the whole (size-capped) file is read.
            if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES
                || ! sodium_crypto_sign_verify_detached($decoded, (string) file_get_contents($to), (string) $key)) {
                throw AddonException::of('addon_signature_invalid', $addon->label());
            }
        }

        $header = (string) @file_get_contents($to, length: 20);

        if (substr($header, 0, 4) !== self::ELF_MAGIC) {
            throw AddonException::of('addon_not_executable', $addon->label());
        }

        // And built for this CPU: e_machine, two little-endian bytes at 18.
        if (strlen($header) < 20 || unpack('v', substr($header, 18, 2))[1] !== self::ELF_X86_64) {
            throw AddonException::of('no_build_for_architecture', $addon->label());
        }
    }

    /** @throws AddonException */
    private function download(AddonCli $addon, string $url, string $to): void
    {
        $limit = (int) config('server.addons.download_max_bytes');

        try {
            $response = Http::timeout((int) config('server.addons.install_timeout'))
                ->connectTimeout(10)
                ->withOptions([
                    'protocols' => ['https'],
                    'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
                    'progress' => function ($total, $downloaded) use ($limit): void {
                        if ($total > $limit || $downloaded > $limit) {
                            throw new RuntimeException('addon download exceeds the size limit');
                        }
                    },
                ])
                ->sink($to)
                ->get($url);
        } catch (Throwable $e) {
            report($e);

            throw AddonException::of('addon_download_failed', $addon->label());
        }

        clearstatcache(true, $to);

        if (! $response->successful() || ! is_file($to) || filesize($to) === 0 || filesize($to) > $limit) {
            throw AddonException::of('addon_download_failed', $addon->label());
        }
    }
}
