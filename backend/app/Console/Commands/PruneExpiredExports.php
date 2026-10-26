<?php

namespace App\Console\Commands;

use App\Models\DatabaseExport;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Removes the exports whose user asked for them to be deleted after a while
 * (`keep_days` on POST /databases/{database}/export, Dipali 2026-10-26).
 *
 * Only exports with an `expires_at`: one without is kept until the user
 * deletes it, which is the default and what every export from before this
 * did. A queued or running one is never touched — its job is still going to
 * write the file.
 */
class PruneExpiredExports extends Command
{
    protected $signature = 'exports:prune-expired';

    protected $description = 'Remove database exports past the date their user chose';

    public function handle(ActivityLogger $activityLogger): int
    {
        $expired = DatabaseExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', DatabaseExport::IN_FLIGHT)
            ->get();

        $expired->each(function (DatabaseExport $export) use ($activityLogger): void {
            $export->deleteWithFile();

            // No actor: nobody pressed delete. The entry says when and which,
            // so a file the user expected to find is not a mystery.
            $activityLogger->log('database.export_expired', null, [
                'name' => $export->database_name,
                'file' => (string) $export->file,
            ]);
        });

        $this->info("Removed {$expired->count()} expired export(s).");

        return self::SUCCESS;
    }
}
