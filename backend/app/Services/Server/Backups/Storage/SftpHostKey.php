<?php

namespace App\Services\Server\Backups\Storage;

use Closure;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Reads an SSH server's public host key and renders it as a fingerprint.
 *
 * This is the "first use" half of trust-on-first-use: the key seen on the first connection is
 * recorded and every later connection is pinned to it, so a destination that starts answering
 * with a different key stops the backup instead of receiving a site's database and `.env`.
 *
 * ⚠️ The first connection is unauthenticated; TOFU only detects a *change*. For certainty, compare
 * the fingerprint shown in the UI with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.
 *
 * Format (same as OpenSSH and `SftpConnectionProvider`): `sha256:` plus the unpadded base64 of
 * the SHA-256 digest of the binary key.
 */
class SftpHostKey
{
    /** @var Closure(string, int): SSH2 */
    private Closure $connector;

    /**
     * @param  null|callable(string, int): SSH2  $connector
     *                                                       Defaults to a real SSH2 connection. Tests inject a fake so
     *                                                       the suite never opens a socket.
     */
    public function __construct(?callable $connector = null)
    {
        $this->connector = $connector !== null
            ? Closure::fromCallable($connector)
            : static fn (string $host, int $port): SSH2 => new SSH2($host, $port, 15);
    }

    /**
     * The host's fingerprint, or null if it could not be read.
     *
     * Null is not surfaced as an error: the probe that follows fails for the same reason with a
     * more useful message.
     */
    public function fingerprint(string $host, int $port): ?string
    {
        try {
            $publicKey = ($this->connector)($host, $port)->getServerPublicHostKey();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($publicKey)) {
            return null;
        }

        // `ssh-rsa AAAAB3Nza… comment` — the middle field is the key.
        $parts = explode(' ', $publicKey, 3);

        if (! isset($parts[1])) {
            return null;
        }

        $binaryKey = base64_decode($parts[1], true);

        if ($binaryKey === false || $binaryKey === '') {
            return null;
        }

        return 'sha256:'.rtrim(base64_encode(hash('sha256', $binaryKey, true)), '=');
    }
}
