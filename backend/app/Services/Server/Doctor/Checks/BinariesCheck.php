<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\ServerOps;

/**
 * Are the tools the panel shells out to actually installed?
 *
 * Sudo being permitted says nothing about the binary existing: a missing tool fails when a
 * feature is used, as a 500 with the cause deep in the logs.
 *
 * Split into required and optional: a panel with no `ufw` only loses firewall rules, and calling
 * that broken would train operators to ignore the report. Missing optional tools are a warning
 * naming the feature they cost.
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
        'psql' => 'PostgreSQL databases',
        'redis-cli' => 'Redis',
        'fnm' => 'Node version management',
        'wp' => 'WordPress sites',
        'phpenmod' => 'PHP extension toggles',
        'hostnamectl' => 'hostname setting',
        'timedatectl' => 'timezone setting',
        'zip' => 'compressing files in the Files feature',
        'rsync' => 'the Staging Area feature',
        // Only needed when an npm dependency has no prebuilt binary for the site's Node version
        // (common on non-LTS, rare on LTS); better named here than found via a buried gyp error.
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
     * Delegated to `ServerOps::binaryExists()`, which searches sudo's `secure_path`: an
     * unprivileged PATH has no /usr/sbin, so half of REQUIRED would look missing. `DockerCheck`
     * shares it (an absent and an ungranted binary give the same `sudo -n` output).
     */
    private function exists(string $binary): bool
    {
        return $this->serverOps->binaryExists($binary);
    }
}
