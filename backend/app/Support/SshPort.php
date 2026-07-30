<?php

namespace App\Support;

/**
 * The port SSH is actually listening on.
 *
 * Three features need this now — the firewall (so it never closes the way in),
 * fail2ban (so it bans the right port), and settings (which changes it) — and
 * each of them getting it wrong locks somebody out of their own server. It
 * therefore has exactly one source: the managed drop-in the Settings feature
 * writes, falling back to the configured default only when nothing has
 * changed it.
 *
 * Reading a default instead of the real value is the same mistake that had
 * fail2ban banning port 22 on a server whose SSH had been moved.
 */
class SshPort
{
    public static function current(): int
    {
        $dir = (string) config('server.sshd_config_dir', '/etc/ssh/sshd_config.d');

        foreach (glob(rtrim($dir, '/').'/*.conf') ?: [] as $file) {
            if (preg_match('/^\s*Port\s+(\d+)/mi', (string) @file_get_contents($file), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return (int) config('server.ssh_port', 22);
    }
}
