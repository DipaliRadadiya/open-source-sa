<?php

namespace App\Console\Commands;

use App\Services\Runtime\LifecycleCatalog;
use Illuminate\Console\Command;

class RefreshRuntimeLifecycle extends Command
{
    protected $signature = 'runtimes:refresh-lifecycle';

    protected $description = 'Refresh cached Node/PHP support and end-of-life dates from upstream';

    public function handle(LifecycleCatalog $catalog): int
    {
        $counts = $catalog->refresh();

        $this->info("node: {$counts['node']} majors, php: {$counts['php']} versions");

        return self::SUCCESS;
    }
}
