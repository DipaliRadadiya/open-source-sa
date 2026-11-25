<?php

namespace App\Services\Server\AppShell;

use App\Services\Panel\PanelPhpBinary;

/**
 * The four files container login is made of, rendered and nothing more.
 *
 * Pure like SudoersFile: a wrong sudoers rule or sshd block can lock people out of the server,
 * so the content is asserted and validated before anything is installed.
 * {@see AppShellInstaller} owns the writes.
 *
 *  - the helper: a standalone PHP script, root:root 0755, the only thing that
 *    runs docker for these users (resources/app-shell/helper.php);
 *  - the login wrapper: what sshd forces every member's login through; it
 *    hands the original command to the helper as one argument;
 *  - the sudoers drop-in: one NOPASSWD rule, for the group, for the helper;
 *  - the sshd drop-in: the `Match Group` block.
 */
class AppShellFiles
{
    public function __construct(private PanelPhpBinary $php) {}

    /**
     * Are all the paths set, absolute and plain? Every write checks this first, so a config that
     * lost the block (stale cache) fails the step instead of running `tee` and `mv` on an empty
     * path. Plain because two are written unquoted into sshd and sudo syntax; the sudoers name
     * must have no dot, since sudo skips such files, which keeps the `.new` copy inert during visudo.
     */
    public function configured(): bool
    {
        foreach ([$this->mapPath(), $this->helperPath(), $this->loginPath(), $this->sudoersPath()] as $path) {
            if (preg_match('#^/[A-Za-z0-9._/-]+$#D', $path) !== 1 || str_contains($path, '..')) {
                return false;
            }
        }

        return ! str_contains(basename($this->sudoersPath()), '.')
            && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $this->group()) === 1
            && preg_match('/^[A-Za-z0-9._-]+\.conf$/D', (string) config('server.app_shell.sshd_drop_in')) === 1;
    }

    public function group(): string
    {
        return (string) config('server.app_shell.group', 'sv-app-shell');
    }

    public function mapPath(): string
    {
        return (string) config('server.app_shell.map');
    }

    public function helperPath(): string
    {
        return (string) config('server.app_shell.helper');
    }

    public function loginPath(): string
    {
        return (string) config('server.app_shell.login');
    }

    public function sudoersPath(): string
    {
        return (string) config('server.app_shell.sudoers');
    }

    public function sshdPath(): string
    {
        return rtrim((string) config('server.sshd_config_dir'), '/').'/'.config('server.app_shell.sshd_drop_in');
    }

    /**
     * The helper, with the panel's own PHP as its interpreter.
     *
     * `-n` so no php.ini is read: the helper runs as root for non-administrators, and an ini file
     * could change what it does. It needs only PHP core (json, pcre, proc_open, syslog). The
     * panel's own binary, since OpenLiteSpeed may have no `/usr/bin/php`.
     */
    public function helper(): string
    {
        $source = (string) file_get_contents(resource_path('app-shell/helper.php'));

        $source = str_replace(
            "const MAP = '/etc/panel-app-shell/map.json';",
            'const MAP = '.var_export($this->mapPath(), true).';',
            $source,
        );

        return '#!'.$this->php->path()." -n\n".$source;
    }

    /**
     * What sshd runs instead of anything a member asks for.
     *
     * Not root, and only forwards: sudo resets the environment, so SSH_ORIGINAL_COMMAND is passed
     * as a single argument. Unset (a plain `ssh -t`) and empty both arrive as '' and open a shell.
     */
    public function login(): string
    {
        return implode("\n", [
            '#!/bin/sh',
            '# Managed by the Control panel (container login). Edits are lost on the next update.',
            'exec /usr/bin/sudo -n '.$this->helperPath().' "${SSH_ORIGINAL_COMMAND-}"',
            '',
        ]);
    }

    /**
     * One rule: the group may run the helper as root, with no password.
     *
     * No `Defaults` line (see SudoersFile::render()): a setting this sudo build does not know
     * invalidates the whole file.
     */
    public function sudoers(): string
    {
        return implode("\n", [
            '# Managed by the Control panel (container login). Rewritten by',
            '# `artisan containers:sync-shell-access` on every panel update.',
            '#',
            '# Members are Docker app owners whose SSH login opens their own',
            "# app's container. The helper takes the caller from sudo and reaches",
            '# only the containers the panel lists for them; it never runs anything',
            '# on the host for them, and never passes them a docker option.',
            '%'.$this->group().' ALL=(root) NOPASSWD: '.$this->helperPath(),
            '',
        ]);
    }

    /**
     * The `Match Group` block.
     *
     * A Match block in an included file ends with that file, so it cannot reach users outside the
     * group. All forwarding is off and `~/.ssh/rc` is not run: ForceCommand alone still allows
     * `ssh -L`/`-R` tunnels from the host, and `.ssh/rc` is a host command.
     */
    public function sshd(): string
    {
        return implode("\n", [
            '# Managed by the Control panel (container login). Rewritten by',
            '# `artisan containers:sync-shell-access`; change it from the panel.',
            '#',
            "# A member's SSH login opens their Docker app's container instead of",
            '# a shell on this server. Users outside the group are not affected.',
            'Match Group '.$this->group(),
            '    ForceCommand '.$this->loginPath(),
            '    DisableForwarding yes',
            '    AllowTcpForwarding no',
            '    AllowStreamLocalForwarding no',
            '    X11Forwarding no',
            '    AllowAgentForwarding no',
            '    PermitTunnel no',
            '    GatewayPorts no',
            '    PermitUserRC no',
            '',
        ]);
    }
}
