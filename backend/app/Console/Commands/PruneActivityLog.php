<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityRetention;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * The daily clean-up behind the activity log's "keep for" choice
 * (2026-10-29). Does nothing while the choice is "keep everything", the
 * default.
 */
class PruneActivityLog extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Delete activity log entries and finished task runs older than the chosen retention';

    public function handle(ActivityRetention $retention, ActivityLogger $activity): int
    {
        $months = $retention->months();

        if ($months === null) {
            $this->info('Activity log is kept forever; nothing to do.');

            return self::SUCCESS;
        }

        $removed = $retention->prune();

        if ($removed['activity'] + $removed['runs'] > 0) {
            // Written after the delete, so it is never one of the rows removed.
            $activity->log('setting.activity_entries_deleted', properties: [
                'count' => $removed['activity'],
                'runs' => $removed['runs'],
                'months' => $months,
            ]);
        }

        $this->info("Removed {$removed['activity']} activity entries and {$removed['runs']} finished runs older than {$months} months.");

        return self::SUCCESS;
    }
}
