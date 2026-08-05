<?php

namespace App\Services\Server;

use App\Exceptions\Server\SystemUser\SystemUserSshFailedException;
use App\Models\SystemUser;

class SshKeyManager
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
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
     * A plausible SSH public key: `<type> <base64-blob> [comment]`.
     */
    public function isValidPublicKey(string $publicKey): bool
    {
        $parts = preg_split('/\s+/', trim($publicKey));

        if (count($parts) < 2) {
            return false;
        }

        if (! preg_match('/^(ssh-rsa|ssh-ed25519|ssh-dss|ecdsa-sha2-\S+)$/', $parts[0])) {
            return false;
        }

        $blob = base64_decode($parts[1], true);

        return $blob !== false && $blob !== '';
    }

    /**
     * Rewrite the user's authorized_keys from the DB rows (the source of
     * truth), then re-assert ownership.
     *
     * Every step goes through ServerOps/ManagedFile, not PHP's own File
     * facade: $systemUser's home directory belongs to THEM, not to whatever
     * account php-fpm runs as, so a raw mkdir()/file_put_contents() here
     * fails with a bare "Permission denied" and no reference to trace it by
     * — the exact failure ManagedFile exists to prevent, just missed here.
     */
    public function sync(SystemUser $systemUser): void
    {
        $sshDir = rtrim($systemUser->home_path, '/').'/.ssh';
        $keys = $systemUser->sshKeys()->pluck('public_key')->implode("\n");
        $context = ['feature' => 'system_user', 'op' => 'ssh_keys.sync', 'system_user' => $systemUser->username];

        $steps = [
            fn () => $this->serverOps->run(['mkdir', '-p', $sshDir], $context),
            fn () => $this->serverOps->run(['chmod', '0700', $sshDir], $context),
            fn () => $this->files->put($sshDir.'/authorized_keys', $keys === '' ? '' : $keys."\n", $context),
            fn () => $this->serverOps->run(['chmod', '0600', $sshDir.'/authorized_keys'], $context),
            fn () => $this->serverOps->run(['chown', '-R', $systemUser->username.':'.$systemUser->username, $sshDir], $context),
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
