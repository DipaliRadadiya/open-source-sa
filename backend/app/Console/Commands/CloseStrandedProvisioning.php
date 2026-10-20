<?php

namespace App\Console\Commands;

use App\Services\Server\Applications\StrandedProvisioning;
use Illuminate\Console\Command;

/**
 * Marks failed the sites whose install job died without saying so — see
 * {@see StrandedProvisioning}. Without it such a site shows "provisioning"
 * forever and cannot be retried or deleted.
 */
class CloseStrandedProvisioning extends Command
{
    protected $signature = 'applications:close-stranded';

    protected $description = 'Mark failed the sites whose install job died without finishing';

    public function handle(StrandedProvisioning $stranded): int
    {
        $closed = $stranded->closeAll();

        $this->info("Marked {$closed} stranded install(s) failed.");

        return self::SUCCESS;
    }
}
