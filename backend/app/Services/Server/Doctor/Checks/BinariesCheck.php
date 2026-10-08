<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\ServerOps;

/**
 * Are the tools the panel shells out to actually installed?
 *
 * Sudo being permitted says nothing about the binary existing. A missing tool
 * fails at the moment a feature is used, as a 500 with a reference, and the
 * cause ("ufw is not installed on this box") is three log lines deep — while
 * every other check reports healthy. That is the shape of "some routes return
 * errors after setup".
 *
 * Split deliberately into required and optional. A panel with no `ufw` cannot
 * do firewall rules, but it can do everything else, and calling that broken
 * would train the operator to ignore the report. Optional tools that are
 * missing are a warning naming the feature they cost.
 */
class BinariesCheck implements DoctorCheck
{
    /** No feature works without these. */
    private const REQUIRED = [
        'systemctl', 'useradd', 'userdel', 'usermod', 'chpasswd', 'gpasswd',
        'getent', 'tee', 'mkdir', 'chown', 'chmod', 'rm', 'cp', 'mv', 'ln',
        'tar', 'unzip', 'curl', 'git', 'ps', 'ss', 'df', 'du',
    ];

    /** Each costs exactly one feature, named so the report is actionable. */
    private const OPTIONAL = [
        'ufw' => 'firewall',
        'fail2ban-client' => 'fail2ban',
        'mysql' => 'MySQL/MariaDB databases',
        'mongosh' => 'MongoDB databases',
        // Missing from this list until 2026-09-14, which made the one check
        // whose job is catching an absent-or-ungranted binary blind to the
        // engine that has an installer, a driver and a whole remote-access
        // feature. Exactly the shape of the 2026-09-07 sudoers gap: a binary
        // the panel calls, allowlisted in config, and nothing verifying the
        // grant reached /etc/sudoers.d on a server installed before it.
        'psql' => 'PostgreSQL databases',
        'redis-cli' => 'Redis',
        'fnm' => 'Node version management',
        'wp' => 'WordPress sites',
        'phpenmod' => 'PHP extension toggles',
        'hostnamectl' => 'hostname setting',
        'timedatectl' => 'timezone setting',
        'zip' => 'compressing files in the Files feature',
        'rsync' => 'the Staging Area feature',
        // Only needed when an npm dependency has no prebuilt binary for the
        // Node version a site was created on -- which is most of the time on
        // a non-LTS Node, and rarely on an LTS one. Named here because the
        // alternative is finding out four minutes into an n8n install, from
        // a gyp error buried in thousands of npm warnings.
        'make' => 'building native Node modules (n8n, NodeBB)',
        'g++' => 'building native Node modules (n8n, NodeBB)',
    ];

    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'binaries';
    }

    public function run(): array
    {
        $missingRequired = array_values(array_filter(
            self::REQUIRED,
            fn (string $binary): bool => ! $this->exists($binary),
        ));

        if ($missingRequired !== []) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.binaries_missing', ['tools' => implode(', ', $missingRequired)]),
                'fix' => 'doctor.fixes.binaries_required',
            ];
        }

        $missingOptional = [];

        foreach (self::OPTIONAL as $binary => $feature) {
            if (! $this->exists($binary)) {
                $missingOptional[] = $binary.' ('.$feature.')';
            }
        }

        if ($missingOptional !== []) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.binaries_optional_missing', ['tools' => implode(', ', $missingOptional)]),
                'fix' => 'doctor.fixes.binaries_optional',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => __('doctor.details.binaries_ok', ['count' => count(self::REQUIRED) + count(self::OPTIONAL)]),
            'fix' => null,
        ];
    }

    /**
     * Delegated to `ServerOps::binaryExists()` rather than asked here.
     *
     * The search path is sudo's `secure_path` and the reason is subtle (an
     * unprivileged PATH has no /usr/sbin on it, so half of REQUIRED looks
     * missing on a box where it works). Two copies of that reasoning is one
     * copy too many — and the second caller is `DockerCheck`, which needs it
     * for a sharper reason: an absent binary and an ungranted one are the same
     * string coming out of `sudo -n`.
     */
    private function exists(string $binary): bool
    {
        return $this->serverOps->binaryExists($binary);
    }
}
