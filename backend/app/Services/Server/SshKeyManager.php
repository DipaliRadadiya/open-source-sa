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
     * One line matters: authorized_keys is line-oriented, so a value with a newline is two keys
     * to sshd but one row to the panel, letting a key log in that the panel never showed.
     *
     * The blob must also name its own type as its first length-prefixed string; base64 alone
     * accepts anything.
     */
    public function isValidPublicKey(string $publicKey): bool
    {
        $publicKey = trim($publicKey);

        // Any control character but a tab: \n and \r are a second line, the rest do not belong.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $publicKey)) {
            return false;
        }

        $parts = preg_split('/[ \t]+/', $publicKey);

        if (count($parts) < 2) {
            return false;
        }

        // `sk-…@openssh.com`: hardware-token keys, which sshd accepts like any other.
        if (! preg_match('/^(ssh-rsa|ssh-ed25519|ssh-dss|ecdsa-sha2-\S+|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)$/', $parts[0])) {
            return false;
        }

        // Padding included: PHP decodes a key whose closing `=` was lost, sshd refuses it.
        if (strlen($parts[1]) % 4 !== 0) {
            return false;
        }

        $blob = base64_decode($parts[1], true);

        if ($blob === false || strlen($blob) < 4) {
            return false;
        }

        $fields = $this->blobFields($blob);

        if ($fields === null || ($fields[0] ?? null) !== $parts[0]) {
            return false;
        }

        // The whole key, not just its start: a truncated key is still valid base64 and names its
        // type. Each type has a fixed field count (RFC 4253 §6.6, RFC 5656 §3.1, RFC 8709 §4,
        // OpenSSH PROTOCOL.u2f).
        $expected = match ($parts[0]) {
            'ssh-ed25519' => 2,
            'ssh-rsa' => 3,
            'ssh-dss' => 5,
            'sk-ssh-ed25519@openssh.com' => 3,
            'sk-ecdsa-sha2-nistp256@openssh.com' => 4,
            default => 3, // ecdsa-sha2-*: type, curve, point
        };

        if (count($fields) !== $expected) {
            return false;
        }

        // An Ed25519 public key is exactly 32 bytes.
        if (in_array($parts[0], ['ssh-ed25519', 'sk-ssh-ed25519@openssh.com'], true)) {
            return strlen($fields[1]) === 32;
        }

        return true;
    }

    /**
     * The length-prefixed fields of a key blob, or null when it does not split into whole fields.
     *
     * @return array<int, string>|null
     */
    private function blobFields(string $blob): ?array
    {
        $fields = [];
        $offset = 0;
        $size = strlen($blob);

        while ($offset < $size) {
            if ($size - $offset < 4) {
                return null;
            }

            $length = unpack('N', substr($blob, $offset, 4))[1];

            if ($length > $size - $offset - 4) {
                return null;
            }

            $fields[] = substr($blob, $offset + 4, $length);
            $offset += 4 + $length;
        }

        return $fields;
    }

    /** RSA keys shorter than this are refused: OpenSSH itself refuses below 1024 and calls under 2048 weak. */
    public const MIN_RSA_BITS = 2048;

    /**
     * Why a well-formed key will not be taken, or null when it will.
     *
     * - `dsa`: OpenSSH disabled DSA in 7.0 and removed it in 9.8, so logins with it fail.
     * - `weak_rsa`: an RSA modulus under {@see MIN_RSA_BITS} bits.
     *
     * Separate from {@see isValidPublicKey()}: the file merge and server sync must still
     * recognise a hand-added DSA line.
     */
    public function rejection(string $publicKey): ?string
    {
        $parts = preg_split('/[ \t]+/', trim($publicKey));

        return match ($parts[0] ?? '') {
            'ssh-dss' => 'dsa',
            'ssh-rsa' => $this->rsaBits((string) ($parts[1] ?? '')) < self::MIN_RSA_BITS ? 'weak_rsa' : null,
            default => null,
        };
    }

    /**
     * The modulus size of an `ssh-rsa` blob: string "ssh-rsa", mpint e,
     * mpint n (RFC 4253 §6.6). 0 when it cannot be read.
     */
    public function rsaBits(string $base64): int
    {
        $blob = base64_decode($base64, true);
        $offset = 0;
        $fields = [];

        for ($i = 0; $i < 3; $i++) {
            if ($blob === false || strlen($blob) < $offset + 4) {
                return 0;
            }

            $length = unpack('N', substr($blob, $offset, 4))[1];
            $fields[] = substr($blob, $offset + 4, $length);
            $offset += 4 + $length;
        }

        $modulus = ltrim($fields[2], "\0");

        if ($modulus === '') {
            return 0;
        }

        return (strlen($modulus) - 1) * 8 + strlen(decbin(ord($modulus[0])));
    }

    /**
     * Rewrite the user's authorized_keys from the DB rows (the source of truth).
     *
     * Every step runs AS THE USER (`runuser -u`), never as root. The user owns ~/.ssh and can
     * plant a symlink there; root would follow it and overwrite and chmod the target (e.g.
     * /etc/passwd). As the user, a planted link reaches only what the user could already write.
     * For the same reason there is no `chown`: `chown -R` over a user-controlled tree is the
     * same mistake.
     *
     * Through ServerOps, not PHP's File facade: the home is not the panel's, and a raw write
     * fails with a bare "Permission denied" and no reference.
     */
    public function sync(SystemUser $systemUser, ?string $removedKey = null): void
    {
        $sshDir = rtrim($systemUser->home_path, '/').'/.ssh';
        $file = $sshDir.'/authorized_keys';
        $context = ['feature' => 'system_user', 'op' => 'ssh_keys.sync', 'system_user' => $systemUser->username];
        $asUser = ['runuser', '-u', $systemUser->username, '--'];

        $panelKeys = $systemUser->sshKeys()->pluck('public_key')->all();

        $steps = [
            fn () => $this->serverOps->run([...$asUser, 'mkdir', '-p', $sshDir], $context),
            fn () => $this->serverOps->run([...$asUser, 'chmod', '0700', $sshDir], $context),
            function () use ($asUser, $file, $context, $panelKeys, $removedKey) {
                // Read first: writing the panel's keys over the whole file would drop keys
                // added by hand on the server.
                $current = $this->serverOps->run([...$asUser, 'cat', $file], $context, expectedExitCodes: [1]);

                // Only a missing file may be read as empty; a denial or unreadable file would
                // end in overwriting whatever it holds.
                $missing = ! $current->denied && $current->exitCode() === 1
                    && str_contains($current->errorOutput(), 'No such file');

                if ($current->failed() && ! $missing) {
                    return $current;
                }

                return $this->serverOps->run(
                    [...$asUser, 'tee', $file],
                    array_merge($context, ['path' => $file]),
                    input: $this->merged($current->ok ? $current->output() : '', $panelKeys, $removedKey),
                );
            },
            fn () => $this->serverOps->run([...$asUser, 'chmod', '0600', $file], $context),
        ];

        foreach ($steps as $step) {
            $result = $step();

            if ($result->failed()) {
                // The reference of whichever step failed; it maps to the server-ops log line
                // with the real stderr.
                throw new SystemUserSshFailedException($result->reference);
            }
        }
    }

    public const BLOCK_BEGIN = '# BEGIN panel-managed keys (edit outside this block)';

    public const BLOCK_END = '# END panel-managed keys';

    /**
     * The file with the panel's keys in a block of their own and everything else as it was.
     *
     * Lines outside the block are kept, minus any panel key or the one just removed. The
     * removed key matters on files written before the block existed: a deleted key outside any
     * block would read as hand-added and keep its access.
     *
     * @param  array<int, string>  $panelKeys
     */
    public function merged(string $current, array $panelKeys, ?string $removedKey = null): string
    {
        $managed = array_map(fn (string $key) => $this->fingerprint($key), [...$panelKeys, ...array_filter([$removedKey])]);
        $kept = [];
        $inBlock = false;

        foreach (preg_split('/\r?\n/', $current) ?: [] as $line) {
            if (trim($line) === self::BLOCK_BEGIN) {
                $inBlock = true;

                continue;
            }

            if (trim($line) === self::BLOCK_END) {
                $inBlock = false;

                continue;
            }

            if ($inBlock) {
                continue;
            }

            if ($this->isValidPublicKey($line) && in_array($this->fingerprint($line), $managed, true)) {
                continue;
            }

            $kept[] = $line;
        }

        $outside = rtrim(implode("\n", $kept));
        $block = $panelKeys === []
            ? ''
            : self::BLOCK_BEGIN."\n".implode("\n", array_map('trim', $panelKeys))."\n".self::BLOCK_END."\n";

        return match (true) {
            $outside === '' => $block,
            $block === '' => $outside."\n",
            default => $outside."\n\n".$block,
        };
    }
}
