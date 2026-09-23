<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\Server\Applications\SiteRootLock;
use Illuminate\Console\Command;

/**
 * Lift the immutable flag from every site root — the undo for SiteRootLock.
 *
 * For rolling the lock back, or for an operator who needs to change the top of
 * a site's directory by hand. The next `sites:resync` locks them again, which
 * every deploy runs, so this is a temporary state by design.
 */
class UnlockSiteRoots extends Command
{
    protected $signature = 'sites:unlock';

    protected $description = 'Remove the immutable flag from every site root (re-applied by sites:resync)';

    public function handle(SiteRootLock $rootLock): int
    {
        $count = 0;

        foreach (Application::query()->with('systemUser')->get() as $application) {
            if ($application->systemUser === null || $rootLock->isLocked($application) !== true) {
                continue;
            }

            $rootLock->unlock($application);
            $count++;
        }

        $this->info(sprintf('Unlocked %d site root(s). The next sites:resync locks them again.', $count));

        return self::SUCCESS;
    }
}
