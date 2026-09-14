<?php

namespace App\Services\Server\Backups\Storage;

use Closure;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Reads an SSH server's public host key and renders it as a fingerprint.
 *
 * This is the "first use" half of trust-on-first-use. The panel records what
 * the host presented the first time it connected, and every connection after
 * that is pinned to it — so a destination that starts answering with a
 * different key stops the backup instead of handing a site's database and
 * `.env` to whoever is now on the other end.
 *
 * ⚠️ The first connection is, unavoidably, unauthenticated. TOFU cannot verify
 * a key it has never seen; what it buys is detection of a *change*. An
 * operator who needs certainty can read the fingerprint off the server
 * (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`) and compare it with the
 * one the panel displays — which is why `host_fingerprint` is shown in the UI
 * rather than kept hidden.
 *
 * The format is the one `SftpConnectionProvider` accepts and the one OpenSSH
 * prints: `sha256:` followed by the unpadded base64 of the SHA-256 digest of
 * the binary key.
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
     * Null is not an error worth surfacing on its own: the probe that follows
     * will fail for the same reason and will say something more useful about
     * it than "could not read a host key" — which, to someone who typed a
     * wrong hostname, explains nothing.
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
