<?php

namespace App\Console\Commands;

use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\Runtimes\NodeRuntime;
use Illuminate\Console\Command;

/**
 * Bring an existing server's Node in line with what a fresh install now does.
 *
 * Two things install.sh and the Node screen used to leave behind, both found
 * on a real server (2026-09-23):
 *
 *  - no `node`/`npm`/`npx` in /usr/local/bin. install.sh set fnm's default
 *    alias but never linked it, so a Node app installed without a pinned
 *    version could not find npm at all;
 *  - versions installed from the Node screen owned by root, because fnm runs
 *    elevated, while updating their npm runs as the panel account — EACCES.
 *
 * Run by install.sh and the panel's own updater, and in the manual deploy
 * runbook: a fix that only reaches new installs leaves every existing server
 * broken. Idempotent, and non-fatal — it reports and exits 0 rather than
 * failing an update over Node.
 */
class RepairNodeRuntime extends Command
{
    protected $signature = 'runtimes:repair-node';

    protected $description = 'Link the default Node into /usr/local/bin and hand every installed version to the fnm owner';

    public function handle(NodeRuntime $node): int
    {
        if (! $node->fnmInstalled()) {
            $this->info('fnm is not installed; nothing to repair.');

            return self::SUCCESS;
        }

        $versions = array_column($node->versions(), 'version');

        foreach ($versions as $version) {
            $node->adoptOwnership($version);
        }

        try {
            $default = $node->linkDefault();
        } catch (SettingOperationException $e) {
            $this->warn('The default Node could not be linked into /usr/local/bin — reference '.$e->reference);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d Node version(s) checked; %s',
            count($versions),
            $default === null ? 'no default set.' : "node, npm and npx point at {$default}.",
        ));

        return self::SUCCESS;
    }
}
