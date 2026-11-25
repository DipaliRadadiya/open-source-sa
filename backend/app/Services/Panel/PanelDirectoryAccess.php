<?php

namespace App\Services\Panel;

use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Who may enter the panel's own install directory.
 *
 * Other local accounts (site and SSH users) must not read the checkout: `bootstrap/cache/config.php`
 * (APP_KEY, Redis password) and `database/database.sqlite` are `644`, and the next `config:cache`
 * rewrites `644` anyway. With the key every encrypted column decrypts; with the Redis password a
 * job can be pushed onto the panel's queue, which runs with the panel's sudo grant.
 *
 * So the top directory is closed, the way HomeDirectoryAccess closes a site owner's home: an ACL
 * with pass-through (`--x`) for the web server's account only. Everything underneath keeps its
 * modes. The panel's own account owns the directory, so it is not listed.
 */
class PanelDirectoryAccess
{
    public const SECURED = 'secured';

    public const ALREADY = 'already';

    /** Not the panel's install directory as far as this can tell; left alone. */
    public const SKIPPED = 'skipped';

    public const NO_ACL = 'no_acl';

    public const NO_READER = 'no_reader';

    public const FAILED = 'failed';

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private HomeDirectoryAccess $homes,
    ) {}

    /**
     * The checkout: the directory holding `backend/`.
     */
    public function root(): string
    {
        return rtrim(dirname(base_path()), '/');
    }

    public function secure(): string
    {
        $root = $this->root();

        if (! $this->isPanelRoot($root)) {
            return self::SKIPPED;
        }

        if (! $this->homes->ensureTools()) {
            return self::NO_ACL;
        }

        $reader = $this->reader();

        if ($reader === null) {
            // Closing it without letting the web server through would take the panel offline.
            return self::NO_READER;
        }

        if ($this->verify($root, $reader)) {
            return self::ALREADY;
        }

        $applied = $this->serverOps->run(
            ['setfacl', '-m', "u:{$reader}:--x,o::---", $root],
            ['feature' => 'panel', 'op' => 'panel_dir_close'],
            timeout: 15,
        )->ok;

        return $applied && $this->verify($root, $reader) ? self::SECURED : self::FAILED;
    }

    /**
     * Undo it: the one-step rollback behind `panel:open-directory`.
     */
    public function open(): bool
    {
        $root = $this->root();

        if (! $this->isPanelRoot($root)) {
            return false;
        }

        $this->serverOps->run(['setfacl', '-b', $root], ['feature' => 'panel', 'op' => 'panel_dir_acl_remove'], timeout: 15);

        return $this->serverOps->run(['chmod', 'o+rx', $root], ['feature' => 'panel', 'op' => 'panel_dir_open'], timeout: 15)->ok;
    }

    private function reader(): ?string
    {
        $reader = $this->webServers->driver()->siteReaderUser();

        return $reader === null || $reader === '' ? null : $reader;
    }

    private function verify(string $root, string $reader): bool
    {
        $result = $this->serverOps->run(['getfacl', '-cp', $root], ['feature' => 'panel', 'op' => 'panel_dir_acl_read'], timeout: 15);

        if ($result->failed()) {
            return false;
        }

        $acl = array_map('trim', explode("\n", $result->output()));

        return in_array("user:{$reader}:--x", $acl, true) && in_array('other::---', $acl, true);
    }

    /**
     * A real directory at least two levels deep that holds this backend and is owned by the
     * backend's account. Never `/`, `/var` or a shared parent: `o::---` there would lock the
     * server out of far more than the panel.
     */
    private function isPanelRoot(string $root): bool
    {
        if ($root === '' || substr_count($root, '/') < 2 || is_link($root) || ! is_dir($root)) {
            return false;
        }

        if (in_array($root, ['/var/www', '/home', '/opt', '/srv', '/usr/local'], true)) {
            return false;
        }

        $owner = @fileowner($root);

        return $owner !== false && $owner === @fileowner(base_path('artisan'));
    }
}
