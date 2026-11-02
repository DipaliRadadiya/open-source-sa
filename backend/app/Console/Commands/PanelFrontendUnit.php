<?php

namespace App\Console\Commands;

use App\Services\Panel\FrontendUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Bring the panel's web interface unit up to what install.sh now writes.
 *
 * Two lines, each added by install.sh after servers were already installed:
 * `HOSTNAME=127.0.0.1` (bug #15: the interface listened on every address) and
 * `NoNewPrivileges=yes` (review finding S1: the interface's processes could
 * use the panel account's root-equivalent sudo grant). This adds whichever is
 * missing and reloads systemd. It does not restart the interface: the updater
 * and the deploy runbook both restart it straight after, and that restart is
 * when the change applies.
 *
 * Runs as root (the unit is root's). Idempotent.
 */
class PanelFrontendUnit extends Command
{
    protected $signature = 'panel:frontend-unit
        {--dry-run : Report whether the unit is current, without writing}';

    protected $description = "Make the panel's web interface listen on 127.0.0.1 only, without sudo";

    public function handle(FrontendUnit $frontend): int
    {
        $path = $frontend->unitPath();

        if (! is_file($path)) {
            $this->warn("{$path} does not exist; nothing to change.");

            return self::SUCCESS;
        }

        $desired = FrontendUnit::desired((string) file_get_contents($path));

        if ($desired === null) {
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$path} would be updated (loopback only, no new privileges).");

            return self::SUCCESS;
        }

        // Temporary file beside the unit and a rename: a half-written unit is
        // an interface systemd will not start.
        $temporary = $path.'.panel-tmp';

        if (file_put_contents($temporary, $desired) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            $this->error("Could not write {$path}; the interface keeps listening as before.");

            return self::FAILURE;
        }

        $reload = Process::timeout(30)->run(['systemctl', 'daemon-reload']);

        if ($reload->failed()) {
            $this->warn('systemctl daemon-reload failed: '.trim($reload->errorOutput()));
        }

        $this->info("{$path} now listens on 127.0.0.1 without sudo (applies at the next restart of the interface).");

        return self::SUCCESS;
    }
}
