<?php

namespace App\Console\Commands;

use App\Services\Panel\OlsWebAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Bind OpenLiteSpeed's WebAdmin console to 127.0.0.1, as install.sh now does
 * (review finding S5), on a server installed before it did.
 *
 * Rewrites the console's listener and restarts OpenLiteSpeed, which is
 * graceful: sites keep serving while the old workers drain. Nothing to do on
 * a server without OpenLiteSpeed, or one already bound.
 *
 * Runs as root (the file is root's). Idempotent.
 */
class PanelOlsWebAdmin extends Command
{
    protected $signature = 'panel:ols-webadmin
        {--dry-run : Report whether the console is bound to 127.0.0.1, without writing}';

    protected $description = "Make OpenLiteSpeed's WebAdmin console listen on 127.0.0.1 only";

    public function handle(OlsWebAdmin $webAdmin): int
    {
        $path = $webAdmin->configPath();

        if (! is_file($path)) {
            $this->info("{$path} does not exist (not an OpenLiteSpeed server); nothing to change.");

            return self::SUCCESS;
        }

        $desired = OlsWebAdmin::desired((string) file_get_contents($path));

        if ($desired === null) {
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$path} would be updated (WebAdmin on ".OlsWebAdmin::ADDRESS.').');

            return self::SUCCESS;
        }

        // Temporary file beside the config and a rename: a half-written file
        // is a console OpenLiteSpeed may refuse to start.
        $temporary = $path.'.panel-tmp';

        if (file_put_contents($temporary, $desired) === false || ! chmod($temporary, 0644) || ! rename($temporary, $path)) {
            @unlink($temporary);
            $this->error("Could not write {$path}; the WebAdmin console keeps listening as before.");

            return self::FAILURE;
        }

        $restart = Process::timeout(60)->run((array) config('server.web_server_drivers.openlitespeed.reload_command'));

        if ($restart->failed()) {
            $this->warn('OpenLiteSpeed did not restart ('.trim($restart->errorOutput()).'); the console moves at its next restart.');

            return self::SUCCESS;
        }

        $this->info("{$path} now listens on ".OlsWebAdmin::ADDRESS.' only (use an SSH tunnel to reach it).');

        return self::SUCCESS;
    }
}
