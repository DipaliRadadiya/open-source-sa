<?php

namespace App\Services\Server\Settings;

use Illuminate\Support\Facades\Process;

/**
 * Is Ubuntu installing its own updates right now?
 *
 * On a server that booted minutes ago the answer is usually yes: the installer
 * says "done" as soon as the panel works, while unattended-upgrades may still
 * be busy for a few minutes. Anything the panel installs with apt meanwhile
 * waits for it (ServerOps::apt), so the setup page says so rather than look
 * stuck.
 *
 * Read-only and without sudo, two ways, either of which counts:
 *
 *  - `systemctl show` on the two units Ubuntu runs its updates in. A oneshot
 *    unit is `activating` for as long as it runs. Any account may ask.
 *  - a process running `unattended-upgrade` itself, for a run started some
 *    other way. /proc/<pid>/cmdline is world-readable. Only that exact name:
 *    `unattended-upgrade-shutdown` stays alive on an idle server, which is the
 *    false "busy" install.sh's apt_lock_is_held warns about.
 *
 * Never the dpkg lock: its files are root's, and probing them would need a new
 * sudo grant for a hint on a screen.
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
