<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * OpenLiteSpeed's own WebAdmin console, and the address it listens on.
 *
 * Review finding S5: OpenLiteSpeed ships the console on `*:7080`, and
 * install.sh relied on the firewall to keep it in — but the installer never
 * enables ufw, so on a stock Ubuntu box it answered on every address. Nothing
 * of the panel's uses it, so it belongs on 127.0.0.1, where an operator still
 * reaches it through an SSH tunnel. install.sh now writes that; this carries
 * it to servers installed before.
 */
class OlsWebAdmin
{
    public const ADDRESS = '127.0.0.1:7080';

    public const UNIT = 'lshttpd';

    /** The name every OpenLiteSpeed server process runs under. */
    public const PROCESS = 'litespeed';

    public function configPath(): string
    {
        return (string) config('server.web_server_drivers.openlitespeed.webadmin_config', '/usr/local/lsws/admin/conf/admin_config.conf');
    }

    /**
     * The config with the console's :7080 listener moved to 127.0.0.1, or
     * null when there is nothing to do: it is there already, or nothing
     * listens on 7080 (an operator who moved the console chose its address).
     * The same rewrite as install.sh's harden_ols_webadmin.
     */
    public static function desired(string $config): ?string
    {
        $next = preg_replace('/^([ \t]*address[ \t]+)\S+:7080[ \t]*$/m', '${1}'.self::ADDRESS, $config);

        return $next === null || $next === $config ? null : $next;
    }

    /** True when the config asks for the console on 127.0.0.1:7080. */
    public static function bound(string $config): bool
    {
        return preg_match('/^[ \t]*address[ \t]+'.preg_quote(self::ADDRESS, '/').'[ \t]*$/m', $config) === 1;
    }

    /**
     * The addresses OpenLiteSpeed itself listens on for :7080 right now, as
     * `ss` prints them (`0.0.0.0:7080`, `127.0.0.1:7080`, …). Other programs
     * on the port are not ours to judge, so they are left out.
     *
     * @return list<string>
     */
    public function liveAddresses(): array
    {
        $result = Process::timeout(15)->run(['ss', '-ltnpH', 'sport = :7080']);

        if ($result->failed()) {
            return [];
        }

        $addresses = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (isset($fields[3]) && str_contains($line, '"'.self::PROCESS.'"')) {
                $addresses[] = $fields[3];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Stop OpenLiteSpeed, wait until no server process is left, start it.
     * Null on success, otherwise what went wrong.
     *
     * Not `lswsctrl restart`. A graceful restart hands the running server's
     * listening sockets to the new one, so the new one inherits `*:7080`,
     * cannot bind 127.0.0.1:7080 beside it, and exits ("Can't listen at
     * address adminListener: Address already in use … Fatal error in
     * configuration, exit!"). The old server keeps serving its old config:
     * the console never moved, and from then on every graceful restart (each
     * site change) failed the same way. Measured on a real server on
     * 2026-11-03 (finding N1). install.sh does the same (restart_ols_cold).
     *
     * The unit's own stop is graceful too (`lswsctrl delay-stop`,
     * KillMode=none): it returns at once, and a draining server may linger
     * for gracefulRestartTimeout (300 s). So: wait, then TERM, then KILL.
     */
    public function restartCold(int $grace = 15): ?string
    {
        $stop = Process::timeout(60)->run(['systemctl', 'stop', self::UNIT]);

        if ($stop->failed()) {
            return 'systemctl stop '.self::UNIT.': '.trim($stop->errorOutput());
        }

        if (! $this->waitForExit($grace)) {
            $this->signal('-TERM');

            if (! $this->waitForExit($grace)) {
                $this->signal('-KILL');

                if (! $this->waitForExit(5)) {
                    return self::PROCESS.' processes would not exit, so '.self::UNIT.' was left stopped rather than started beside them';
                }
            }
        }

        // A unit systemd had stopped restarting (start-limit-hit) refuses a start.
        Process::timeout(15)->run(['systemctl', 'reset-failed', self::UNIT]);

        $start = Process::timeout(60)->run(['systemctl', 'start', self::UNIT]);

        if ($start->failed()) {
            return 'systemctl start '.self::UNIT.': '.trim($start->errorOutput());
        }

        return null;
    }

    /**
     * The PIDs of every OpenLiteSpeed server process.
     *
     * `ps -C` and `kill` rather than pgrep/pkill: the same match on the
     * process name, with binaries the panel is already allowed to run
     * (server.privilege.binaries), so this widens no grant.
     *
     * @return list<int>
     */
    private function serverPids(): array
    {
        $output = Process::timeout(15)->run(['ps', '-C', self::PROCESS, '-o', 'pid='])->output();

        return array_values(array_filter(
            array_map('intval', preg_split('/\s+/', trim($output)) ?: []),
            fn (int $pid) => $pid > 1,
        ));
    }

    private function signal(string $signal): void
    {
        $pids = $this->serverPids();

        if ($pids !== []) {
            Process::timeout(15)->run(['kill', $signal, ...array_map('strval', $pids)]);
        }
    }

    /** True once no OpenLiteSpeed process is left; false after $seconds. */
    private function waitForExit(int $seconds): bool
    {
        for ($waited = 0; ; $waited++) {
            if ($this->serverPids() === []) {
                return true;
            }

            if ($waited >= $seconds) {
                return false;
            }

            Sleep::for(1)->second();
        }
    }
}
