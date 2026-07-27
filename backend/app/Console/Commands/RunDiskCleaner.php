<?php

namespace App\Console\Commands;

use App\Actions\Server\DiskCleaner\RunCleanupAction;
use App\Models\DiskCleanerSchedule;
use App\Services\Server\DiskCleaner\DiskCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Scheduled disk-cleaner tick. Registered on the Laravel scheduler (see
 * routes/console.php) and gated entirely by the DB profile — so there is no
 * user-editable cron file that could drift with the Cronjobs feature.
 */
class RunDiskCleaner extends Command
{
    protected $signature = 'disk-cleaner:run';

    protected $description = 'Run the automatic disk cleaner when enabled, due, and over the threshold.';

    public function handle(DiskCleaner $cleaner, RunCleanupAction $action): int
    {
        $schedule = DiskCleanerSchedule::query()->first();

        if (! $schedule || ! $schedule->enabled || ! $schedule->isDue(Date::now())) {
            return self::SUCCESS;
        }

        // Threshold gate: wait (don't mark as run) until the disk is full
        // enough, so it fires as soon as usage crosses the line this slot.
        if ($schedule->threshold_percent !== null
            && $cleaner->disk()['percent'] < $schedule->threshold_percent) {
            return self::SUCCESS;
        }

        // Safe + available categories only — caution categories never run
        // unattended.
        $safe = collect($cleaner->targets())
            ->filter(fn ($target) => $target->safe() && $target->available())
            ->map(fn ($target) => $target->key())
            ->all();

        $keys = array_values(array_intersect($schedule->categories ?? [], $safe));

        if ($keys !== []) {
            try {
                $action->execute($keys, 'scheduled');
            } catch (Throwable $e) {
                report($e);
            }
        }

        $schedule->forceFill(['last_run_at' => Date::now()])->save();

        return self::SUCCESS;
    }
}
