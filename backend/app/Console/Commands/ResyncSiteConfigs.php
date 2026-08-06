<?php

namespace App\Console\Commands;

use App\Services\Server\Applications\SiteConfigResyncer;
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

    public function handle(SiteConfigResyncer $resyncer): int
    {
        $result = $resyncer->run();

        $this->info(sprintf(
            '%d site(s): %d updated, %d already current, %d failed.%s',
            $result['total'],
            $result['updated'],
            $result['unchanged'],
            count($result['failed']),
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

        return self::SUCCESS;
    }
}
