<?php

namespace App\Services\Panel;

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
}
