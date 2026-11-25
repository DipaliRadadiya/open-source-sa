<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Process;

/**
 * Can the panel actually run the privileged commands it depends on?
 *
 * php-fpm runs unprivileged, so without a working sudo every system user, firewall rule, service
 * restart and package install fails (while faked tests pass).
 *
 * It asks `sudo -n -l <binary>` rather than running the tools: that resolves the real sudoers
 * rules for the real user and changes nothing.
 */
class PrivilegeCheck implements DoctorCheck
{
    /** Checked first and on their own: if these are denied, sudo itself is broken. */
    private const REPRESENTATIVE = ['useradd', 'systemctl', 'ufw', 'apt-get', 'tee'];

    public function key(): string
    {
        return 'privilege';
    }

    /**
     * The account php-fpm and the queue worker run as.
     *
     * Taken from the owner of the panel's code (install.sh chowns it to that account). Null when
     * it cannot be determined, which is reported rather than treated as "fine".
     */
    private function serviceUser(): ?string
    {
        if (! function_exists('posix_getpwuid')) {
            return null;
        }

        $owner = @fileowner(base_path('artisan'));

        if ($owner === false) {
            return null;
        }

        $user = posix_getpwuid($owner);

        // root owning the checkout means there is no unprivileged account to check.
        return ($user['name'] ?? null) && $user['name'] !== 'root' ? $user['name'] : null;
    }

    /**
     * @return array<int, string>
     */
    private function probe(?string $binary, ?string $serviceUser): array
    {
        $probe = array_filter(['sudo', '-n', '-l', $binary], fn ($part): bool => $part !== null);

        return $serviceUser === null
            ? $probe
            : array_merge(['runuser', '-u', $serviceUser, '--'], $probe);
    }

    public function run(): array
    {
        // Whoever ran `panel:doctor` (often root, which needs no sudo) is not who does the work.
        // A green tick for root hides a broken grant for the panel account, so when running as
        // root, ask about the account the panel actually runs as.
        $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $serviceUser = $asRoot ? $this->serviceUser() : null;

        if ($asRoot && $serviceUser === null) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.privilege_root_unknown'),
                'fix' => 'doctor.fixes.privilege_unknown_user',
            ];
        }

        if (! config('server.privilege.sudo', true)) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.privilege_sudo_disabled'),
                'fix' => 'doctor.fixes.privilege_disabled',
            ];
        }

        $denied = [];

        foreach (self::REPRESENTATIVE as $binary) {
            $result = Process::timeout(10)->run($this->probe($binary, $serviceUser));

            if (! $result->successful()) {
                $denied[] = $binary;
            }
        }

        if ($denied !== []) {
            return [
                'status' => 'fail',
                'detail' => $serviceUser === null
                    ? __('doctor.details.privilege_denied', ['commands' => implode(', ', $denied)])
                    : __('doctor.details.privilege_denied_for', ['user' => $serviceUser, 'commands' => implode(', ', $denied)]),
                'fix' => 'doctor.fixes.privilege',
            ];
        }

        // sudo works. Now check the grant covers everything *this build* will run: the sample
        // above only finds a broken sudo, not an out-of-date grant (the state after every panel
        // update without re-running `install.sh`).
        //
        // One `sudo -n -l` rather than one per binary: dozens of round trips make the doctor slow.
        $ungranted = $this->ungranted($serviceUser);

        if ($ungranted !== []) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.privilege_missing', ['commands' => implode(', ', $ungranted)]),
                'fix' => 'doctor.fixes.privilege_outdated',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $serviceUser === null
                ? __('doctor.details.privilege_ok', ['count' => count((array) config('server.privilege.binaries', []))])
                : __('doctor.details.privilege_ok_for', ['count' => count((array) config('server.privilege.binaries', [])), 'user' => $serviceUser]),
            'fix' => null,
        ];
    }

    /**
     * Binaries the panel will try to elevate that this server's sudoers rule does not cover.
     *
     * @return array<int, string>
     */
    private function ungranted(?string $serviceUser): array
    {
        // Through the service account, like the probes above: as root this lists `(ALL) ALL`
        // with no absolute paths, so every binary would be reported missing.
        $listing = Process::timeout(15)->run($this->probe(null, $serviceUser));

        if (! $listing->successful()) {
            // sudo answered above, so this is a sudoers that permits commands but not listing
            // them. Reporting every binary as missing would be worse than reporting nothing.
            return [];
        }

        // The rule is written with absolute paths; the panel calls bare names
        // and lets sudo resolve them through secure_path, so compare basenames.
        preg_match_all('#/(?:usr/)?(?:s?bin|local/s?bin)/([a-z0-9_.*-]+)#i', $listing->output(), $matches);

        $granted = array_unique($matches[1]);

        // `php-fpm*` is granted as a wildcard: one binary per installed PHP version.
        $wildcards = array_filter($granted, fn (string $entry) => str_ends_with($entry, '*'));

        return array_values(array_filter(
            (array) config('server.privilege.binaries', []),
            function (string $binary) use ($granted, $wildcards) {
                if (in_array($binary, $granted, true)) {
                    return false;
                }

                foreach ($wildcards as $wildcard) {
                    if (str_starts_with($binary, rtrim($wildcard, '*'))) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
