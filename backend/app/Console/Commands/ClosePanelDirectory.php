<?php

namespace App\Console\Commands;

use App\Services\Panel\PanelDirectoryAccess;
use Illuminate\Console\Command;

/**
 * Close the panel's install directory to every local account except the web
 * server's — see PanelDirectoryAccess.
 *
 * Run by install.sh and by `sites:resync`, which every deploy and the panel's
 * own updater already run, so existing servers are closed on their next
 * update. Idempotent, and never fails: a directory left open is reported, not
 * a reason to abort an update. `--open` is the one-step rollback.
 */
class ClosePanelDirectory extends Command
{
    protected $signature = 'panel:close-directory
        {--open : Undo it: remove the ACL and make the directory world-readable again}';

    protected $description = "Close the panel's install directory to other local users";

    public function handle(PanelDirectoryAccess $access): int
    {
        $root = $access->root();

        if ($this->option('open')) {
            $access->open()
                ? $this->info("Panel directory {$root} is open to other users again.")
                : $this->warn("Panel directory {$root} was not changed.");

            return self::SUCCESS;
        }

        $result = $access->secure();

        match ($result) {
            PanelDirectoryAccess::SECURED => $this->info("Panel directory: {$root} closed to other users."),
            PanelDirectoryAccess::ALREADY => $this->info("Panel directory: {$root} already closed."),
            PanelDirectoryAccess::SKIPPED => $this->warn("Panel directory: {$root} left alone (not a directory the panel owns)."),
            PanelDirectoryAccess::NO_ACL => $this->warn("Panel directory: {$root} left open, setfacl is missing."),
            PanelDirectoryAccess::NO_READER => $this->warn("Panel directory: {$root} left open, the web server's account is not known."),
            default => $this->warn("Panel directory: {$root} left open, the ACL did not take; see the server-ops log."),
        };

        return self::SUCCESS;
    }
}
