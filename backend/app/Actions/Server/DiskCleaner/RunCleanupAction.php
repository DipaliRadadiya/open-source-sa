<?php

namespace App\Actions\Server\DiskCleaner;

use App\Exceptions\Server\DiskCleaner\DiskCleanerException;
use App\Models\DiskCleanerRun;
use App\Services\ActivityLogger;
use App\Services\Server\DiskCleaner\DiskCleaner;
use App\Services\Server\DiskCleaner\KeepDays;
use App\Support\Bytes;

class RunCleanupAction
{
    public function __construct(
        private DiskCleaner $cleaner,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Clean each selected category, measuring freed space (estimate before − after).
     * Runs synchronously. Returns refreshed disk usage + per-category freed bytes.
     *
     * @param  array<int, string>  $keys
     * @param  string  $trigger  `manual` (default) or `scheduled` — recorded
     *                           on the run-history row.
     * @param  int|null  $keepDays  the one keep choice for every category
     *                              ({@see KeepDays}); null = pre-choice behaviour
     * @return array<string, mixed>
     */
    public function execute(array $keys, string $trigger = 'manual', ?int $keepDays = null): array
    {
        $cleaned = [];
        $freedTotal = 0;

        foreach ($keys as $key) {
            $target = $this->cleaner->find($key);

            // Defence in depth: the FormRequest already whitelists keys, but this op is destructive.
            if (! $target || ! $target->available()) {
                abort(404, __('errors/disk-cleaner.not_found'));
            }

            // Same keep choice as the clean, or the freed number compares two selections.
            $before = $target->estimate($keepDays);
            $result = $target->clean($keepDays);

            if ($result->failed()) {
                // Recorded before throwing: a scheduled clean swallows the exception, so
                // without this row a nightly failure looks like a cleaner that never ran.
                DiskCleanerRun::create([
                    'trigger' => $trigger,
                    'categories' => $keys,
                    'keep_days' => $keepDays,
                    'freed' => collect($cleaned)->pluck('freed', 'key')->all(),
                    'freed_total' => $freedTotal,
                    'status' => 'failed',
                    'disk_percent' => $this->cleaner->disk()['percent'],
                ]);

                $this->activityLogger->log($this->verb($trigger, failed: true), null, [
                    'categories' => implode(', ', $keys),
                    'category' => $key,
                    'keep_days' => $keepDays ?? KeepDays::UNCHOSEN,
                ]);

                throw new DiskCleanerException($result->reference);
            }

            $freed = max(0, $before - $target->estimate($keepDays));
            $freedTotal += $freed;

            $cleaned[] = [
                'key' => $key,
                'freed' => $freed,
                'freed_human' => Bytes::human($freed),
            ];
        }

        $disk = $this->cleaner->disk();

        $run = DiskCleanerRun::create([
            'trigger' => $trigger,
            'categories' => $keys,
            'keep_days' => $keepDays,
            'freed' => collect($cleaned)->pluck('freed', 'key')->all(),
            'freed_total' => $freedTotal,
            'status' => 'completed',
            'disk_percent' => $disk['percent'],
        ]);

        $this->activityLogger->log($this->verb($trigger), null, [
            'categories' => implode(', ', $keys),
            'freed' => Bytes::human($freedTotal),
            'keep_days' => $keepDays ?? KeepDays::UNCHOSEN,
        ]);

        return [
            'run_id' => $run->id,
            'disk' => $disk,
            'cleaned' => $cleaned,
            'freed_total' => $freedTotal,
            'freed_total_human' => Bytes::human($freedTotal),
        ];
    }

    /**
     * The activity verb for this run.
     *
     * A separate verb rather than a `trigger` property: the activity filters are built from
     * the lang keys and filter on the indexed `action` column. It also lets the automatic
     * sentence stand alone, since a scheduled run has no actor.
     */
    private function verb(string $trigger, bool $failed = false): string
    {
        $automatic = $trigger === 'scheduled';

        return match (true) {
            $automatic && $failed => 'disk_cleaner.auto_clean_failed',
            $automatic => 'disk_cleaner.auto_cleaned',
            $failed => 'disk_cleaner.clean_failed',
            default => 'disk_cleaner.cleaned',
        };
    }
}
