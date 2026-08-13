<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Drops file-manager trash older than the retention window.
 *
 * Without this the trash is a slow disk-space leak: every delete keeps a full
 * copy indefinitely, on a machine whose whole job is running out of disk
 * quietly. The retention was written and then not wired to anything, which is
 * the same failure as not writing it — worse, because the API said it was
 * happening.
 *
 * Daily, not per-minute: the unit is days, and each site costs a `find`.
 */
class PruneFileTrash extends Command
{
    protected $signature = 'files:prune-trash';

    protected $description = 'Delete file manager trash older than the retention window';

    public function handle(FileBrowser $files): int
    {
        $applications = Application::query()
            // A site still provisioning has no trash and may have no directory
            // to look in; a failed one might, so it is included.
            ->whereNot('status', ApplicationStatus::Pending)
            ->with('systemUser')
            ->get();

        foreach ($applications as $application) {
            try {
                $files->pruneTrash($application);
            } catch (Throwable $e) {
                // One unreachable site must not stop the sweep for the rest.
                // The server-ops log already carries the detail.
                $this->warn("skipped {$application->name}: {$e->getMessage()}");
            }
        }

        $this->info("Swept trash for {$applications->count()} application(s).");

        return self::SUCCESS;
    }
}
