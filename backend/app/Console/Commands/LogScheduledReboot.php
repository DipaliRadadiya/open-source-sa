<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Records that the scheduled reboot is about to happen.
 *
 * The reboot itself is cron's job, not this command's. The cron entry runs
 * this and then runs `shutdown` regardless of how it went — a machine the
 * administrator scheduled to restart must restart even if the panel's database
 * is unreachable. Doing the shutdown from here would make an audit record a
 * precondition of the reboot, which is the wrong way round.
 *
 * No actor: nobody pressed anything. The entry reads as System, the same as an
 * automatic disk clean, and that is the honest description of a machine acting
 * on an instruction left for it days ago.
 */
class LogScheduledReboot extends Command
{
    protected $signature = 'server:log-scheduled-reboot';

    protected $description = 'Record the activity entry for a reboot the schedule is about to perform.';

    public function handle(ActivityLogger $log): int
    {
        $log->log('setting.auto_rebooted');

        return self::SUCCESS;
    }
}
