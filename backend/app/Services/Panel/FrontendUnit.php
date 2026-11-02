<?php

namespace App\Services\Panel;

/**
 * The panel's web interface unit, and the lines it must carry.
 *
 * Bug #15: install.sh set only `PORT`, and Next's standalone server binds
 * `process.env.HOSTNAME || '0.0.0.0'` — so the interface answered on every
 * address, with only the firewall between port 3100 and the internet. Every
 * web server the panel configures reaches it on 127.0.0.1 (install.sh's
 * nginx, Apache and OpenLiteSpeed blocks alike), so loopback is all it needs.
 *
 * Review finding S1: the unit runs as the panel account, whose sudo grant is
 * root-equivalent, and nothing stopped the interface's processes using it —
 * a code-execution bug in Next was `sudo -n sh` away from root. The interface
 * never needs sudo, so `NoNewPrivileges=yes` blocks setuid for this unit.
 */
class FrontendUnit
{
    public const LISTEN = 'Environment=HOSTNAME=127.0.0.1';

    public const NO_NEW_PRIVILEGES = 'NoNewPrivileges=yes';

    public function service(): string
    {
        return (string) config('panel_update.services.frontend', 'panel-frontend.service');
    }

    public function unitPath(): string
    {
        return rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/').'/'.$this->service();
    }

    /**
     * The unit with every line it is missing, or null when there is nothing
     * to do.
     */
    public static function desired(string $unit): ?string
    {
        $changed = false;

        foreach ([self::withLoopback(...), self::withNoNewPrivileges(...)] as $apply) {
            $next = $apply($unit);

            if ($next !== null) {
                $unit = $next;
                $changed = true;
            }
        }

        return $changed ? $unit : null;
    }

    /**
     * The unit with the loopback line added after `Environment=PORT=`, or
     * null when there is nothing to do: it is already there, the unit sets
     * its own HOSTNAME (the operator's choice, left alone), or it has no
     * PORT line to anchor on (not a unit install.sh wrote).
     */
    public static function withLoopback(string $unit): ?string
    {
        if (preg_match('/^\s*Environment=["\']?HOSTNAME=/m', $unit) === 1
            || preg_match('/^\s*Environment=["\']?PORT=.*$/m', $unit, $port, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $end = $port[0][1] + strlen($port[0][0]);

        return substr($unit, 0, $end)."\n".self::LISTEN.substr($unit, $end);
    }

    /**
     * The unit with `NoNewPrivileges=yes` added at the end of [Service], or
     * null when there is nothing to do: the unit already says either way (an
     * operator's `no` is left alone), or it has no [Service] section.
     */
    public static function withNoNewPrivileges(string $unit): ?string
    {
        if (preg_match('/^\s*NoNewPrivileges\s*=/m', $unit) === 1
            || preg_match('/^\[Service\][ \t]*$/m', $unit, $service, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // The end of [Service]: the next section header, or the end of file.
        $from = $service[0][1] + strlen($service[0][0]);
        $end = preg_match('/^\[/m', $unit, $next, PREG_OFFSET_CAPTURE, $from) === 1 ? $next[0][1] : strlen($unit);

        $body = rtrim(substr($unit, 0, $end));
        $rest = substr($unit, $end);

        return $body."\n".self::NO_NEW_PRIVILEGES."\n".($rest === '' ? '' : "\n".$rest);
    }
}
