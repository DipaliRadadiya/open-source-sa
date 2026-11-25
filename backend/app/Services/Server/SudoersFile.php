<?php

namespace App\Services\Server;

use App\Console\Commands\PanelSudoers;

/**
 * The panel's sudoers grant, rendered from the one list that defines it.
 *
 * One list feeds both consumers: install.sh calls `panel:sudoers --print` and the update writes
 * the file, so a binary added to the config cannot be missing from one of them.
 *
 * This class never touches the disk: a malformed sudoers file breaks `sudo` for every account.
 * {@see PanelSudoers} owns the write, validates with `visudo` and installs via a temporary file,
 * so the existing grant survives a bad render.
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
            // Binaries without an entry in `paths` live in /usr/bin.
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
     * php-fpm and the queue worker run as this user. Taken from the update's config, where
     * install.sh records it.
     */
    public function user(): string
    {
        return (string) config('panel_update.app_user', 'panel');
    }

    /**
     * The file to write.
     *
     * Defaults to the account name, as install.sh names it from PANEL_SLUG (which also defaults
     * APP_USER); the config override covers installs that set the two apart.
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
     * One grant line and no `Defaults`. An unknown `Defaults` setting (e.g. `!requiretty`, removed
     * in sudo 1.9.17) is a parse error that invalidates the whole file at `visudo -cqf`, and the
     * target server's sudo may be newer than ours. Add only settings we positively need.
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
            $user.' ALL=(root) NOPASSWD: '.implode(', ', $this->entries()),
            '',
        ]);
    }
}
