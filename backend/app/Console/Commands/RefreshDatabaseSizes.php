<?php

namespace App\Console\Commands;

use App\Services\Server\Databases\DatabaseSizes;
use Illuminate\Console\Command;

/**
 * Keeps the size on the databases list current.
 *
 * The list reads a stored column rather than querying every schema on every
 * request, which is the right trade for a page that may show dozens of
 * databases — but only if something keeps the column up to date. This is that
 * something.
 *
 * No activity log entry: nobody needs to look up when a number was refreshed,
 * and on a five-minute tick it would bury real events. Same reasoning as the
 * metrics samplers.
 */
class RefreshDatabaseSizes extends Command
{
    protected $signature = 'databases:refresh-sizes';

    protected $description = 'Re-measure the stored size of every tracked database.';

    public function handle(DatabaseSizes $sizes): int
    {
        $this->info("Updated {$sizes->refreshAll()} database sizes.");

        return self::SUCCESS;
    }
}
