<?php

namespace App\Console\Commands;

use App\Services\Runtime\NpmCatalog;
use Illuminate\Console\Command;

class RefreshNpmReleases extends Command
{
    protected $signature = 'runtimes:refresh-npm';

    protected $description = 'Refresh the newest npm release of each npm major, and the Node versions it supports';

    public function handle(NpmCatalog $catalog): int
    {
        $this->info("npm: {$catalog->refresh()} majors");

        return self::SUCCESS;
    }
}
