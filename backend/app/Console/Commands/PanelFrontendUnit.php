<?php

namespace App\Console\Commands;

use App\Services\Panel\FrontendUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Make the panel's web interface listen on loopback only (bug #15).
 *
 * install.sh now writes `HOSTNAME=127.0.0.1` into the unit; servers installed
 * before that listen on every address. This adds the line to the existing
 * unit and reloads systemd. It does not restart the interface: the updater
 * and the deploy runbook both restart it straight after, and that restart is
 * when the new address applies.
 *
 * Runs as root (the unit is root's). Idempotent.
 */
class PanelFrontendUnit extends Command
{
    protected $signature = 'panel:frontend-unit
        {--dry-run : Report whether the unit is current, without writing}';

    protected $description = "Make the panel's web interface listen on 127.0.0.1 only";

    public function handle(FrontendUnit $frontend): int
    {
        $path = $frontend->unitPath();

        if (! is_file($path)) {
            $this->warn("{$path} does not exist; nothing to change.");

            return self::SUCCESS;
        }

        $desired = FrontendUnit::withLoopback((string) file_get_contents($path));

        if ($desired === null) {
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$path} would be updated to listen on 127.0.0.1.");

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

        $this->info("{$path} now listens on 127.0.0.1 (applies at the next restart of the interface).");

        return self::SUCCESS;
    }
}
