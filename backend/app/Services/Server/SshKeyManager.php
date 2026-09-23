<?php

namespace App\Services\Server;

use App\Exceptions\Server\SystemUser\SystemUserSshFailedException;
use App\Models\SystemUser;

class SshKeyManager
{
    public function __construct(
        private ServerOps $serverOps,
    ) {}

    /**
     * OpenSSH-style SHA256 fingerprint of a public key.
     */
    public function fingerprint(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        $blob = base64_decode($parts[1] ?? '', true) ?: '';

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * A plausible SSH public key: `<type> <base64-blob> [comment]`, on ONE line.
     *
     * One line is the whole point. authorized_keys is line-oriented, so a
     * value carrying a newline is two keys to sshd — and one key to the panel,
     * which lists a single row and a single fingerprint. The second line was
     * accepted, written and honoured: reproduced on a real server
     * (2026-09-23), logging in with a key the panel never showed.
     *
     * The blob is also asked what it is. Every OpenSSH public key blob starts
     * with its own type as a length-prefixed string, so a blob that does not
     * name the type in front of it is not that key — decoding as base64 alone
     * accepts any base64 at all.
     */
    public function isValidPublicKey(string $publicKey): bool
    {
        $publicKey = trim($publicKey);

        // Any control character but a tab: \n and \r are a second line, and
        // the rest have no business in a key and are not worth reasoning about.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $publicKey)) {
            return false;
        }

        $parts = preg_split('/[ \t]+/', $publicKey);

        if (count($parts) < 2) {
            return false;
        }

        if (! preg_match('/^(ssh-rsa|ssh-ed25519|ssh-dss|ecdsa-sha2-\S+)$/', $parts[0])) {
            return false;
        }

        $blob = base64_decode($parts[1], true);

        if ($blob === false || strlen($blob) < 4) {
            return false;
        }

        $length = unpack('N', substr($blob, 0, 4))[1];

        return substr($blob, 4, $length) === $parts[0];
    }

    /**
     * Rewrite the user's authorized_keys from the DB rows (the source of
     * truth).
     *
     * Every step runs AS THE USER (`runuser -u`), never as root. ~/.ssh lives
     * in a directory the user owns, so the user decides what every name in it
     * points at — and root follows a symlink like anyone else. This used to
     * `tee` and `chmod` as root: a user who replaced authorized_keys with a
     * link had the panel overwrite the link's target with their keys and
     * chmod it 0600. Reproduced on a real server (2026-09-23) against a
     * root-owned file outside the home; aimed at /root/.ssh/authorized_keys it
     * is root, aimed at /etc/passwd it is a dead server. Anything running as
     * the site user can plant that link — a compromised plugin included.
     *
     * As the user, a planted link reaches only what the user could already
     * write, so there is nothing to check for and no check-then-write race.
     * It is also why there is no `chown` any more: files the user creates are
     * the user's, and `chown -R` over a user-controlled tree is the same
     * mistake one step later.
     *
     * Through ServerOps, not PHP's File facade — the home is not the panel's,
     * and a raw write fails with a bare "Permission denied" and no reference.
     */
    public function sync(SystemUser $systemUser): void
    {
        $sshDir = rtrim($systemUser->home_path, '/').'/.ssh';
        $file = $sshDir.'/authorized_keys';
        $keys = $systemUser->sshKeys()->pluck('public_key')->implode("\n");
        $context = ['feature' => 'system_user', 'op' => 'ssh_keys.sync', 'system_user' => $systemUser->username];
        $asUser = ['runuser', '-u', $systemUser->username, '--'];

        $steps = [
            fn () => $this->serverOps->run([...$asUser, 'mkdir', '-p', $sshDir], $context),
            fn () => $this->serverOps->run([...$asUser, 'chmod', '0700', $sshDir], $context),
            fn () => $this->serverOps->run(
                [...$asUser, 'tee', $file],
                array_merge($context, ['path' => $file]),
                input: $keys === '' ? '' : $keys."\n",
            ),
            fn () => $this->serverOps->run([...$asUser, 'chmod', '0600', $file], $context),
        ];

        foreach ($steps as $step) {
            $result = $step();

            if ($result->failed()) {
                // The reference from whichever step failed — the one the
                // operator sees, correlated to the one line in the
                // server-ops log that has the real stderr.
                throw new SystemUserSshFailedException($result->reference);
            }
        }
    }
}
