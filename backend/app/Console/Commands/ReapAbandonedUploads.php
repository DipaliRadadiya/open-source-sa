<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\Server\Applications\ChunkedUpload;
use Illuminate\Console\Command;

/**
 * Deletes part files from uploads nobody finished.
 *
 * `ChunkedUpload::reap()` has existed since the feature shipped and nothing
 * ever called it. Its own docblock describes what that costs: a closed laptop
 * mid-upload leaves the part file behind, uploads have no size limit, and
 * abandoned ones are "a slow disk leak that ends as the outage MIN_FREE_BYTES
 * exists to prevent". The guard refusing new chunks on a full disk was in
 * place; the thing that stops the disk filling in the first place was written
 * and left unwired.
 *
 * Daily rather than every minute: the window is measured in hours, and unlike
 * the backup and disk-cleaner ticks there is no per-application schedule to
 * consult — this walks every site, so running it constantly would be a `find`
 * per site per minute for something that changes on the scale of a day.
 *
 * No activity log entry. Reaping a part file is not a user action and, across
 * every application daily, would bury the entries that are.
 */
class ReapAbandonedUploads extends Command
{
    protected $signature = 'uploads:reap {--hours=24 : Age, in hours, past which an unfinished part file is abandoned}';

    protected $description = 'Delete part files left behind by uploads that were never finished.';

    public function handle(ChunkedUpload $uploads): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $reaped = 0;

        // Chunked because this is every application on the box and the panel
        // shares its memory with the sites it hosts.
        Application::query()
            ->whereNotNull('system_user_id')
            ->with('systemUser')
            ->chunkById(100, function ($applications) use ($uploads, $hours, &$reaped): void {
                foreach ($applications as $application) {
                    // A site whose system user was deleted has no account to
                    // run `find` as; `reap()` would build a `runuser -u ` with
                    // an empty username. Skipped rather than guessed at.
                    if ($application->systemUser === null) {
                        continue;
                    }

                    $uploads->reap($application, $hours);
                    $reaped++;
                }
            });

        $this->info("Swept uploads for {$reaped} applications.");

        return self::SUCCESS;
    }
}
