<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Models\Application;
use App\Services\Server\Applications\ApplicationLogRotation;
use App\Services\Server\DiskCleaner\TruncateLogsTarget;

/**
 * Every hosted site's current log files, in `{home}/{slug}/logs` on every
 * web server ({@see Application::logsPath()}).
 *
 * The cleaner used to look for these under /usr/local/lsws/conf/vhosts/*,
 * where they have not lived since the 2026-09-22 move to one logs directory
 * per site — so no site's logs were ever cleaned, on any stack.
 *
 * Schedulable since 2026-10-26 (Dipali, for servers whose disks fill up). It
 * was manual-only because emptying a site's logs removes its visitor and error
 * history; the keep choice is what makes a schedule acceptable — "keep 7 days"
 * leaves every file still being written and only drops the rotated copies
 * older than a week.
 *
 * The rotated copies are its own: {@see ApplicationLogRotation} keeps 14 days
 * of up to 50 MB each beside the current files, and nothing else cleans them —
 * `rotated_logs` only looks under /var/log.
 */
class SiteLogsTarget extends TruncateLogsTarget
{
    public function key(): string
    {
        return 'site_logs';
    }

    /** `access.log.1`, `error.log.3.gz`, and OpenLiteSpeed's dated copies. */
    protected function archivePattern(): ?string
    {
        return '*.log.*';
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
