<?php

namespace App\Services\Server;

use App\Console\Commands\PanelSudoers;

/**
 * The panel's sudoers grant, rendered from the one list that defines it.
 *
 * There used to be two lists: `bins` in install.sh's configure_sudoers(), and
 * `server.privilege.binaries`. They had to agree and nothing made them. Every
 * privilege bug this panel has shipped came from that — `touch`, `certbot`,
 * `openssl`, `crontab`, `stat`, `mysqldump` were each added to one copy and
 * not the other, and each broke a feature that looked configured, with sudo
 * reporting only "a password is required".
 *
 * Now both consumers render from here: install.sh calls `panel:sudoers
 * --print`, and the update writes the file. Adding a binary to the config is
 * sufficient, and cannot be half-done.
 *
 * **Paths only — this class does not touch the disk.** Writing a sudoers file
 * is the single most dangerous thing the panel does to a server: a malformed
 * one breaks `sudo` for every account on the box, including the operator's.
 * Keeping the rendering pure is what lets the content be asserted, diffed
 * against a real server's file, and validated by `visudo` before anything is
 * installed. {@see PanelSudoers} owns the write, and
 * does it via a temporary file so the existing grant survives a bad render.
 */
class SudoersFile
{
    /**
     * Absolute paths of everything the grant covers, in config order.
     *
     * @return array<int, string>
     */
    public function entries(): array
    {
        $paths = (array) config('server.privilege.paths', []);

        $entries = [];

        foreach ((array) config('server.privilege.binaries', []) as $binary) {
            // Anything without an entry in `paths` lives in /usr/bin. That is
            // the default rather than a lookup failure: the exception map only
            // carries the binaries that are somewhere else, so that the list
            // of *what* the panel may elevate stays readable on its own.
            foreach ($paths[$binary] ?? ['/usr/bin/'.$binary] as $path) {
                $entries[] = $path;
            }
        }

        foreach ((array) config('server.privilege.wildcards', []) as $pattern) {
            $entries[] = $pattern;
        }

        return array_values(array_unique($entries));
    }

    /**
     * The account the grant is written for.
     *
     * php-fpm and the queue worker run as this user, so it is the only account
     * whose sudo access matters. Taken from the update's config because that
     * is where install.sh already records it.
     */
    public function user(): string
    {
        return (string) config('panel_update.app_user', 'panel');
    }

    /**
     * The file to write.
     *
     * install.sh names it from PANEL_SLUG, which also defaults APP_USER — so
     * the account name is the correct derivation for every default install,
     * and the config override covers an operator who set the two apart.
     */
    public function path(): string
    {
        $configured = config('server.privilege.sudoers_file');

        return is_string($configured) && $configured !== ''
            ? $configured
            : '/etc/sudoers.d/'.$this->user();
    }

    /**
     * The complete file content, newline-terminated.
     *
     * `!requiretty` because php-fpm has no terminal; without it every
     * privileged operation fails on distributions that default it on.
     */
    public function render(): string
    {
        $user = $this->user();

        return implode("\n", [
            '# Managed by the Control panel. Generated from server.privilege',
            '# in config/server.php by `artisan panel:sudoers`, which runs at',
            '# install time and again on every panel update.',
            '#',
            '# Edits to this file are lost on the next update. To grant a new',
            '# binary, add it to that config list — it is the only copy, and',
            '# install.sh renders this file from it rather than repeating it.',
            'Defaults:'.$user.' !requiretty',
            $user.' ALL=(root) NOPASSWD: '.implode(', ', $this->entries()),
            '',
        ]);
    }
}
