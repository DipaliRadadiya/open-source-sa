<?php

namespace App\Console\Commands;

use App\Services\Server\AppShell\AppShellInstaller;
use App\Services\Server\AppShell\AppShellMap;
use Illuminate\Console\Command;

/**
 * Put container login in place and rebuild its access map (RC-28).
 *
 * This is how apps that existed before container login get it: the panel
 * update and install.sh both run this, so after an upgrade every Docker app's
 * owner can be switched to container login without reinstalling anything.
 *
 * As the panel account, through its sudo grant, like every other server write
 * (`visudo` is in that grant for the sudoers check). Idempotent: files that
 * are already current are left alone, and sshd is reloaded only when its
 * drop-in actually changed and passed `sshd -t`.
 *
 * A console command rather than an endpoint for the reason
 * {@see RecordFirewallDefaults} gives: it runs from the installer and the
 * updater, where there is no user to authenticate as.
 */
class SyncShellAccess extends Command
{
    protected $signature = 'containers:sync-shell-access
        {--map-only : Rebuild the access map without checking the installed files}';

    protected $description = "Install container login for Docker apps' own users and rebuild its access map";

    public function handle(AppShellInstaller $installer, AppShellMap $map): int
    {
        $failed = false;

        if (! $this->option('map-only')) {
            foreach ($installer->ensure() as $part => $state) {
                $this->line(str_pad($part, 8).' '.$state);
                $failed = $failed || $state === AppShellInstaller::FAILED;
            }
        }

        $written = $map->write(force: true);
        $this->line(str_pad('map', 8).' '.($written === null ? AppShellInstaller::FAILED : count($map->entries()).' app(s)'));

        if ($failed || $written === null) {
            $this->error('Container login is not fully set up; see the server-ops log. Run this command again once the cause is fixed.');

            return self::FAILURE;
        }

        $this->info('Container login is set up.');

        return self::SUCCESS;
    }
}
