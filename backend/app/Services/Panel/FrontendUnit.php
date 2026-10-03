<?php

namespace App\Services\Panel;

/**
 * The panel's web interface unit, and the one line it must carry.
 *
 * Bug #15: install.sh set only `PORT`, and Next's standalone server binds
 * `process.env.HOSTNAME || '0.0.0.0'` — so the interface answered on every
 * address, with only the firewall between port 3100 and the internet. Every
 * web server the panel configures reaches it on 127.0.0.1 (install.sh's
 * nginx, Apache and OpenLiteSpeed blocks alike), so loopback is all it needs.
 */
class FrontendUnit
{
    public const LISTEN = 'Environment=HOSTNAME=127.0.0.1';

    public function service(): string
    {
        return (string) config('panel_update.services.frontend', 'panel-frontend.service');
    }

    public function unitPath(): string
    {
        return rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/').'/'.$this->service();
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
}
