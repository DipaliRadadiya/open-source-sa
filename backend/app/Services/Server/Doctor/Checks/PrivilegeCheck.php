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
     * Checked first, and on their own, because if these are denied then sudo
     * itself is not working and naming sixty other binaries would bury that.
     */
    private const REPRESENTATIVE = ['useradd', 'systemctl', 'ufw', 'apt-get', 'tee'];

    public function key(): string
    {
        return 'privilege';
    }

    /**
     * The account php-fpm and the queue worker run as.
     *
     * Taken from the owner of the panel's own code, which install.sh chowns to
     * that account — rather than from config, where it is not recorded, or
     * from a guess at the name. Null when it cannot be determined, which is
     * reported rather than treated as "fine".
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

        // root owning the checkout means the panel is not running as its own
        // account at all, so there is nothing unprivileged to check.
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
        // Whoever ran `panel:doctor` is not who does the work. An operator
        // reads this from a root shell, and root needs no sudo — so this used
        // to answer "sudo not required" and skip every check, while php-fpm
        // and the queue worker went on failing as the unprivileged panel
        // account. A green tick beside a broken feature is worse than no
        // check: it sends the reader somewhere else entirely. That is exactly
        // what happened when `touch` gained a sudoers grant the running
        // servers did not have.
        //
        // So when this runs as root it drops to the account the panel actually
        // runs as and asks about that one instead.
        $asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $serviceUser = $asRoot ? $this->serviceUser() : null;

        if ($asRoot && $serviceUser === null) {
            return [
                'status' => 'warn',
                'detail' => 'running as root and the panel\'s own account could not be identified, so its sudo access was not checked',
                'fix' => 'doctor.fixes.privilege_unknown_user',
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
            $result = Process::timeout(10)->run($this->probe($binary, $serviceUser));

            if (! $result->successful()) {
                $denied[] = $binary;
            }
        }

        if ($denied !== []) {
            return [
                'status' => 'fail',
                'detail' => 'not permitted'.($serviceUser === null ? '' : ' for '.$serviceUser).': '.implode(', ', $denied),
                'fix' => 'doctor.fixes.privilege',
            ];
        }

        // sudo works. Now the question that actually bites: does the grant on
        // this server cover everything *this build* of the panel will try to
        // run?
        //
        // The five above were always granted, on every install, which is why
        // this check passed for months while certbot, openssl, touch, stat and
        // crontab were all denied — every Let's Encrypt certificate, every
        // self-signed one, every cron job's log file and every chunked upload
        // failing on a server the panel reported as healthy. A sample can only
        // find a sudo that is entirely broken; it cannot find a grant that is
        // merely out of date, which is the state every server reaches the
        // moment the panel is updated and `install.sh` is not re-run.
        //
        // One `sudo -n -l` rather than one per binary: sixty round trips would
        // make the doctor slow enough that people stop running it.
        $ungranted = $this->ungranted($serviceUser);

        if ($ungranted !== []) {
            return [
                'status' => 'fail',
                'detail' => 'granted by sudo but missing: '.implode(', ', $ungranted),
                'fix' => 'doctor.fixes.privilege_outdated',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => count((array) config('server.privilege.binaries', [])).' commands permitted'.($serviceUser === null ? '' : ' for '.$serviceUser),
            'fix' => null,
        ];
    }

    /**
     * Binaries the panel will try to elevate that this server's sudoers rule
     * does not cover.
     *
     * @return array<int, string>
     */
    private function ungranted(?string $serviceUser): array
    {
        // Through the service account, exactly like the probes above. Asked as
        // root this lists root's own `(ALL) ALL`, which contains no absolute
        // paths at all — so the regex below matched nothing and every binary
        // the panel uses was reported missing, on a server whose grant was
        // fine. A check that cries wolf about all 76 is as useless as one that
        // passes silently.
        $listing = Process::timeout(15)->run($this->probe(null, $serviceUser));

        if (! $listing->successful()) {
            // sudo answered for the five above, so a failure here is something
            // else — a locked-down sudoers that permits commands but not
            // listing them. Reporting every binary as missing would be worse
            // than reporting nothing.
            return [];
        }

        // The rule is written with absolute paths; the panel calls bare names
        // and lets sudo resolve them through secure_path, so compare basenames.
        preg_match_all('#/(?:usr/)?(?:s?bin|local/s?bin)/([a-z0-9_.*-]+)#i', $listing->output(), $matches);

        $granted = array_unique($matches[1]);

        // `php-fpm*` is granted as a wildcard — one binary per installed PHP
        // version, so an exact list would need editing every time a version is
        // added through the panel's own feature.
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
