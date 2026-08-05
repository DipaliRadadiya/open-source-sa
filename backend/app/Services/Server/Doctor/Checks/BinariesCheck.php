<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Process;

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
        'redis-cli' => 'Redis',
        'fnm' => 'Node version management',
        'wp' => 'WordPress sites',
        'phpenmod' => 'PHP extension toggles',
        'hostnamectl' => 'hostname setting',
        'timedatectl' => 'timezone setting',
        'zip' => 'compressing files in the Files feature',
        'rsync' => 'the Staging Area feature',
    ];

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
                'detail' => 'missing: '.implode(', ', $missingRequired),
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
                'detail' => 'not installed: '.implode(', ', $missingOptional),
                'fix' => 'doctor.fixes.binaries_optional',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => count(self::REQUIRED) + count(self::OPTIONAL).' tools present',
            'fix' => null,
        ];
    }

    /**
     * sudo's default secure_path on Debian and Ubuntu.
     *
     * Searching the panel account's own PATH is wrong and produced a false
     * report the first time this ran: useradd, userdel, usermod and chpasswd
     * live in /usr/sbin, which is not on an unprivileged user's PATH, so they
     * looked missing on a box where they were present and working. sudo
     * resolves them through secure_path, so that is the path to ask about.
     */
    private const SEARCH_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /**
     * `command -v` rather than is_executable() on a guessed path, so this
     * resolves the binary the same way the real call will.
     */
    private function exists(string $binary): bool
    {
        return Process::timeout(10)
            ->env(['PATH' => self::SEARCH_PATH])
            ->run(['sh', '-c', 'command -v '.escapeshellarg($binary)])
            ->successful();
    }
}
