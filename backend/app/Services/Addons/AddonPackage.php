<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fetches an addon binary Central offers and proves it is the one Central
 * built, before anything is installed from it.
 *
 * Three checks, each for a different failure:
 *
 *   - the SHA-256 Central sent: the file is the one the request named, not a
 *     truncated or swapped download;
 *   - an Ed25519 signature over the file's bytes, against a key configured on
 *     this server: Central, and nobody who merely holds this server's central
 *     token, chose this file. Without a key nothing is installed at all;
 *   - the ELF magic: it is a Linux executable, not an error page that happens
 *     to have been signed.
 *
 * Per `laravel13-security-hardening-research-v2.md [1003–1087]` (the pattern
 * IonCubeLoader follows): TLS only, bounded time and size, and nothing about
 * the response trusted beyond the bytes on disk.
 */
class AddonPackage
{
    private const ELF_MAGIC = "\x7fELF";

    /**
     * The configured public key, raw, or null when there is none usable.
     * A key that is not 32 bytes of base64 is no key.
     */
    public static function signingKey(): ?string
    {
        $key = base64_decode((string) config('server.addons.signing_public_key'), true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    /**
     * Download to `$to` and verify it. The caller deletes `$to`, verified or not.
     *
     * @throws AddonException
     */
    public function fetch(AddonCli $addon, string $url, string $sha256, string $signature, string $to): void
    {
        // Checked first, and before the download: no key means no install, so
        // there is nothing to fetch.
        $key = self::signingKey() ?? throw AddonException::of('addon_signing_key_missing', $addon->label());

        $this->download($addon, $url, $to);

        if (! hash_equals(strtolower($sha256), (string) hash_file('sha256', $to))) {
            throw AddonException::of('addon_checksum_mismatch', $addon->label());
        }

        $signature = base64_decode($signature, true);

        // Ed25519 signs the message itself, not a digest of it, so the whole
        // file is read. It is size-capped above, and the PHP CLI the queue
        // runs under has no memory limit by default.
        if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, (string) file_get_contents($to), $key)) {
            throw AddonException::of('addon_signature_invalid', $addon->label());
        }

        if ((string) @file_get_contents($to, length: 4) !== self::ELF_MAGIC) {
            throw AddonException::of('addon_not_executable', $addon->label());
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
