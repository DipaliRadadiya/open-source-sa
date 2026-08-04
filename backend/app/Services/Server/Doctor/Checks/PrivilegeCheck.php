<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Process;

/**
 * Can the panel actually run the privileged commands it depends on?
 *
 * This is the check that would have caught the panel shipping with a sudoers
 * grant it never used: php-fpm runs unprivileged, so without a working sudo
 * every system user, firewall rule, service restart and package install fails
 * — while every faked test passes.
 *
 * It asks sudo whether the grant exists rather than running the tools, using
 * `sudo -n -l <binary>`. That resolves the real sudoers rules for the real
 * user and changes nothing: running `useradd` to find out if `useradd` works
 * would create an account nobody asked for.
 */
class PrivilegeCheck implements DoctorCheck
{
    /**
     * A sample, not the whole allowlist. These five cover the features that
     * break most visibly — accounts, services, firewall, packages, config
     * writes — and if sudo is broken it is broken for all of them.
     */
    private const REPRESENTATIVE = ['useradd', 'systemctl', 'ufw', 'apt-get', 'tee'];

    public function key(): string
    {
        return 'privilege';
    }

    public function run(): array
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return [
                'status' => 'pass',
                'detail' => 'running as root; sudo not required',
                'fix' => null,
            ];
        }

        if (! config('server.privilege.sudo', true)) {
            return [
                'status' => 'fail',
                'detail' => 'SERVER_OPS_SUDO is disabled but the panel is not root',
                'fix' => 'doctor.fixes.privilege_disabled',
            ];
        }

        $denied = [];

        foreach (self::REPRESENTATIVE as $binary) {
            $result = Process::timeout(10)->run(['sudo', '-n', '-l', $binary]);

            if (! $result->successful()) {
                $denied[] = $binary;
            }
        }

        if ($denied !== []) {
            return [
                'status' => 'fail',
                'detail' => 'not permitted: '.implode(', ', $denied),
                'fix' => 'doctor.fixes.privilege',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => count(self::REPRESENTATIVE).' representative commands permitted',
            'fix' => null,
        ];
    }
}
