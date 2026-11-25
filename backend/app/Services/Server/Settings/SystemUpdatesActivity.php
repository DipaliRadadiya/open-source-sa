<?php

namespace App\Services\Server\Settings;

use Illuminate\Support\Facades\Process;

/**
 * Is Ubuntu installing its own updates right now?
 *
 * Right after boot it usually is, and apt installs by the panel wait for it (ServerOps::apt),
 * so the setup page says so rather than look stuck.
 *
 * Read-only and without sudo; either check counts:
 *
 *  - `systemctl show` on the two units Ubuntu runs its updates in (a oneshot unit is
 *    `activating` while it runs).
 *  - a process running `unattended-upgrade` itself. Only that exact name:
 *    `unattended-upgrade-shutdown` stays alive on an idle server (false "busy").
 *
 * Never probe the dpkg lock: its files are root's and it would need a new sudo grant.
 */
class SystemUpdatesActivity
{
    /** The units apt.systemd.daily runs in: the download, then the install. */
    public const UNITS = ['apt-daily.service', 'apt-daily-upgrade.service'];

    /** Argument only for the tests. */
    public function __construct(private string $procRoot = '/proc') {}

    public function running(): bool
    {
        return $this->unitRunning() || $this->upgradeProcessRunning();
    }

    private function unitRunning(): bool
    {
        $result = Process::timeout(5)->run(['systemctl', 'show', ...self::UNITS, '--property=ActiveState', '--value']);

        if (! $result->successful()) {
            return false;
        }

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $state) {
            if (in_array(trim($state), ['activating', 'deactivating', 'reloading'], true)) {
                return true;
            }
        }

        return false;
    }

    private function upgradeProcessRunning(): bool
    {
        foreach (glob($this->procRoot.'/[0-9]*/cmdline') ?: [] as $file) {
            // A process can end between the glob and the read; that is "not
            // running", not an error.
            $cmdline = @file_get_contents($file);

            if (! is_string($cmdline) || $cmdline === '') {
                continue;
            }

            // The interpreter, then the script: `/usr/bin/python3
            // /usr/bin/unattended-upgrade`. The script alone when exec'd.
            foreach (array_slice(explode("\0", $cmdline), 0, 2) as $arg) {
                if (basename($arg) === 'unattended-upgrade') {
                    return true;
                }
            }
        }

        return false;
    }
}
