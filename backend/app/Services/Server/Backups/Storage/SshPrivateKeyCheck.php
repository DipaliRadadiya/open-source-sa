<?php

namespace App\Services\Server\Backups\Storage;

use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * Whether an SFTP destination's private key can actually be used, asked when
 * it is saved.
 *
 * It was not asked at all: `not a key` saved fine, and the failure arrived at
 * the connection test — or, for a destination nobody tested, at the first
 * backup. Measured with phpseclib 3 (2026-09-23): garbage and a passphrase-
 * protected key given no passphrase fail with the *same* "Unable to read key",
 * so whether the key is encrypted is read from the key itself, and a pasted
 * public key — the most common mistake — gets its own answer.
 */
final class SshPrivateKeyCheck
{
    /**
     * The translation key for what is wrong, or null when the key loads.
     */
    public static function problem(string $key, ?string $passphrase): ?string
    {
        try {
            PublicKeyLoader::loadPrivateKey($key, filled($passphrase) ? $passphrase : false);

            return null;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'not a private key')) {
                return 'storage.validation.sftp_key_is_public';
            }

            if (self::encrypted($key)) {
                return filled($passphrase)
                    ? 'storage.validation.sftp_key_wrong_passphrase'
                    : 'storage.validation.sftp_key_needs_passphrase';
            }

            return 'storage.validation.sftp_key_invalid';
        }
    }

    /**
     * PEM says so in a header; OpenSSH's own format names its cipher inside
     * the base64 body, and `none` means unencrypted.
     */
    private static function encrypted(string $key): bool
    {
        if (str_contains($key, 'ENCRYPTED')) {
            return true;
        }

        if (! preg_match('/-----BEGIN OPENSSH PRIVATE KEY-----(.+?)-----END/s', $key, $m)) {
            return false;
        }

        $body = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);

        // "openssh-key-v1\0", then a length-prefixed cipher name.
        if ($body === false || ! str_starts_with($body, "openssh-key-v1\0") || strlen($body) < 19) {
            return false;
        }

        $length = unpack('N', substr($body, 15, 4))[1];

        return substr($body, 19, $length) !== 'none';
    }
}
