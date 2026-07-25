<?php

namespace App\Services\Server;

use App\Models\SystemUser;
use Illuminate\Support\Facades\File;

class SshKeyManager
{
    public function __construct(private ServerOps $serverOps) {}

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
     * truth), then re-assert ownership (privileged — runs as root on a real
     * server; the file content itself is written here).
     */
    public function sync(SystemUser $systemUser): void
    {
        $sshDir = rtrim($systemUser->home_path, '/').'/.ssh';
        $keys = $systemUser->sshKeys()->pluck('public_key')->implode("\n");

        File::ensureDirectoryExists($sshDir, 0700);
        File::put($sshDir.'/authorized_keys', $keys === '' ? '' : $keys."\n");
        File::chmod($sshDir.'/authorized_keys', 0600);

        $this->serverOps->run(
            ['chown', '-R', $systemUser->username.':'.$systemUser->username, $sshDir],
            ['feature' => 'system_user', 'op' => 'ssh_keys.sync', 'system_user' => $systemUser->username],
        );
    }
}
