<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\TruncateLogsTarget;

/**
 * The current log files of the services the panel runs — web servers,
 * databases, Redis, supervisor, the firewall. Locations come from
 * `server.disk_cleaner.service_log_globs`; see TruncateLogsTarget for how
 * they are found and why.
 *
 * A site's own logs are not here: they are visitor history, not service
 * housekeeping, and they have their own category — SiteLogsTarget.
 */
class ServiceLogsTarget extends TruncateLogsTarget
{
    public function key(): string
    {
        return 'service_logs';
    }

    protected function locations(): array
    {
        $locations = [];

        foreach ((array) config('server.disk_cleaner.service_log_globs', []) as $glob) {
            $directory = dirname((string) $glob);

            // A wildcard in the directory part cannot be one `find` location;
            // the configured globs keep wildcards to the file name.
            if (strpbrk($directory, '*?[') !== false) {
                continue;
            }

            $locations[] = [$directory, basename((string) $glob)];
        }

        return $locations;
    }
}
