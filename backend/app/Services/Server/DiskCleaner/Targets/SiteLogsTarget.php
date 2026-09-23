<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Models\Application;
use App\Services\Server\DiskCleaner\TruncateLogsTarget;

/**
 * Every hosted site's current log files, in `{home}/{slug}/logs` on every
 * web server ({@see Application::logsPath()}).
 *
 * The cleaner used to look for these under /usr/local/lsws/conf/vhosts/*,
 * where they have not lived since the 2026-09-22 move to one logs directory
 * per site — so no site's logs were ever cleaned, on any stack.
 *
 * Not `safe`: emptying a site's access and error logs removes its visitor and
 * error history, which is the site owner's record, not housekeeping. So it is
 * offered for a deliberate manual clean and never runs on a schedule.
 */
class SiteLogsTarget extends TruncateLogsTarget
{
    public function key(): string
    {
        return 'site_logs';
    }

    public function safe(): bool
    {
        return false;
    }

    protected function locations(): array
    {
        return Application::query()
            ->with('systemUser')
            ->get()
            ->filter(fn (Application $application) => $application->systemUser !== null)
            ->map(fn (Application $application) => [$application->logsPath(), '*.log'])
            ->unique(0)
            ->values()
            ->all();
    }
}
