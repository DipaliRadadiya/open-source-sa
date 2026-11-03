<?php

namespace App\Console\Commands;

use App\Services\Panel\OlsWebAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Bind OpenLiteSpeed's WebAdmin console to 127.0.0.1, as install.sh now does
 * (review finding S5), on a server installed before it did.
 *
 * Rewrites the console's listener and restarts OpenLiteSpeed cold: stopped,
 * every server process gone, started. A graceful restart cannot move a
 * listener to another address on the same port (finding N1, see
 * OlsWebAdmin::restartCold), so this costs the sites a few seconds, once.
 * Nothing to do on a server without OpenLiteSpeed, or one already bound.
 *
 * Also repairs a server this command reached while it still restarted
 * gracefully: the file says 127.0.0.1 and OpenLiteSpeed still listens on
 * every address, which fails every later graceful restart.
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

        $config = (string) file_get_contents($path);
        $desired = OlsWebAdmin::desired($config);
        $stale = $desired === null && OlsWebAdmin::bound($config) && $this->listensElsewhere($webAdmin->liveAddresses());

        if ($desired === null && ! $stale) {
            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line($desired !== null
                ? "{$path} would be updated (WebAdmin on ".OlsWebAdmin::ADDRESS.').'
                : 'OpenLiteSpeed would be restarted: it still listens on '.implode(', ', $webAdmin->liveAddresses()).'.');

            return self::SUCCESS;
        }

        if ($desired !== null) {
            // Temporary file beside the config and a rename: a half-written
            // file is a console OpenLiteSpeed may refuse to start.
            $temporary = $path.'.panel-tmp';

            if (file_put_contents($temporary, $desired) === false || ! chmod($temporary, 0644) || ! rename($temporary, $path)) {
                @unlink($temporary);
                $this->error("Could not write {$path}; the WebAdmin console keeps listening as before.");

                return self::FAILURE;
            }
        }

        $failure = $webAdmin->restartCold();

        if ($failure !== null) {
            $this->warn("OpenLiteSpeed did not restart ({$failure}); the console moves at its next cold start.");

            return self::SUCCESS;
        }

        // A started unit is not yet a bound listener: give it a few seconds,
        // and only claim the move once `ss` shows it.
        $live = [];
        for ($try = 0; $try < 10; $try++) {
            $live = $webAdmin->liveAddresses();
            if ($live !== [] && ! $this->listensElsewhere($live)) {
                $this->info("{$path} now listens on ".OlsWebAdmin::ADDRESS.' only (use an SSH tunnel to reach it).');

                return self::SUCCESS;
            }
            Sleep::for(1)->second();
        }

        $this->warn('OpenLiteSpeed restarted, but its console listens on '.($live === [] ? 'nothing yet' : implode(', ', $live)).'; check /usr/local/lsws/logs/error.log.');

        return self::SUCCESS;
    }

    /** @param  list<string>  $live */
    private function listensElsewhere(array $live): bool
    {
        return array_diff($live, [OlsWebAdmin::ADDRESS]) !== [];
    }
}
