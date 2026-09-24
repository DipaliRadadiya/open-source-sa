<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\Applications\SiteRootLock;
use Illuminate\Console\Command;

/**
 * Re-render every live site's vhost from the current templates and lists.
 *
 * Run by the update script after migrations, because the AI bot list, the 8G
 * ruleset and the vhost templates all ship inside the panel: an update
 * changes what a site's config *would* say without changing what it does
 * say. See `SiteConfigResyncer` for why that gap is a correctness problem
 * rather than a missing convenience.
 *
 * Exits 0 even when individual sites fail. A site whose config test failed
 * has already been rolled back and is still serving; failing the command
 * would abort an otherwise-good panel update over it.
 */
class ResyncSiteConfigs extends Command
{
    protected $signature = 'sites:resync';

    protected $description = 'Re-render every live site config from the current templates and bot lists';

    public function handle(SiteConfigResyncer $resyncer, SiteRootLock $rootLock): int
    {
        $result = $resyncer->run();

        // The grant is reported separately because it is the one thing here
        // that can be the *only* reason the web server was restarted. Folded
        // into "updated" it would read as a config change that did not happen;
        // left out entirely, a run saying "0 updated. Web server reloaded."
        // would look like a bug.
        $this->info(sprintf(
            '%d site(s): %d updated, %d already current, %d failed.%s%s',
            $result['total'],
            $result['updated'],
            $result['unchanged'],
            count($result['failed']),
            $result['granted'] > 0
                ? sprintf(' Log access granted for %d site(s).', $result['granted'])
                : '',
            $result['reloaded'] ? ' Web server reloaded.' : '',
        ));

        foreach ($result['failed'] as $failure) {
            $this->warn(sprintf(
                'Left unchanged: %s (#%d)%s',
                $failure['name'],
                $failure['id'],
                $failure['reference'] ? ' — reference '.$failure['reference'] : '',
            ));
        }

        $this->lockSiteRoots($rootLock);

        return self::SUCCESS;
    }

    /**
     * Make every existing site root immutable — see SiteRootLock.
     *
     * Here because this command is already run on every deploy and by the
     * panel's own updater: a lock applied only when a site is created would
     * protect new sites and leave every existing one open, the same shape as a
     * template fix that reaches new sites only.
     *
     * A site root that is not a root-owned directory is reported and left
     * alone. Locking it would pin whatever is there in place — and if the site
     * user has already swapped it, that is exactly what must not be trusted.
     */
    private function lockSiteRoots(SiteRootLock $rootLock): void
    {
        $counts = [SiteRootLock::LOCKED => 0, SiteRootLock::UNSUPPORTED => 0];
        $problems = [];

        foreach (Application::query()->with('systemUser')->get() as $application) {
            if ($application->systemUser === null) {
                continue;
            }

            $result = $rootLock->lock($application);

            if (array_key_exists($result, $counts)) {
                $counts[$result]++;
            } elseif ($result !== SiteRootLock::MISSING) {
                $problems[] = [$application, $result];
            }
        }

        $this->info(sprintf(
            'Site roots: %d locked%s%s.',
            $counts[SiteRootLock::LOCKED],
            $counts[SiteRootLock::UNSUPPORTED] > 0
                ? sprintf(', %d on a filesystem without the immutable flag', $counts[SiteRootLock::UNSUPPORTED])
                : '',
            $problems !== [] ? sprintf(', %d need attention', count($problems)) : '',
        ));

        foreach ($problems as [$application, $result]) {
            $this->warn(sprintf(
                'Not locked: %s (#%d) — %s',
                $application->name,
                $application->id,
                $result === SiteRootLock::UNSAFE
                    ? 'its directory is not a root-owned directory, so it was left alone; check '.$application->rootPath().' (a site server sync adopted: use Lock on the site\'s page)'
                    : 'chattr failed; see the server-ops log',
            ));
        }
    }
}
