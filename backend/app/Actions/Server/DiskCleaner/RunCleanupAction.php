<?php

namespace App\Actions\Server\DiskCleaner;

use App\Exceptions\Server\DiskCleaner\DiskCleanerException;
use App\Models\DiskCleanerRun;
use App\Services\ActivityLogger;
use App\Services\Server\DiskCleaner\DiskCleaner;
use App\Support\Bytes;

class RunCleanupAction
{
    public function __construct(
        private DiskCleaner $cleaner,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Clean each selected category, measuring freed space (estimate before −
     * after). Runs synchronously (Phase 1). Returns refreshed disk usage +
     * per-category freed bytes.
     *
     * @param  array<int, string>  $keys
     * @param  string  $trigger  `manual` (default) or `scheduled` — recorded
     *                           on the run-history row.
     * @return array<string, mixed>
     */
    public function execute(array $keys, string $trigger = 'manual'): array
    {
        $cleaned = [];
        $freedTotal = 0;

        foreach ($keys as $key) {
            $target = $this->cleaner->find($key);

            // Defence in depth — the FormRequest already whitelists to
            // available keys, but never trust that alone for a destructive op.
            if (! $target || ! $target->available()) {
                abort(404, __('errors/disk-cleaner.not_found'));
            }

            $before = $target->estimate();
            $result = $target->clean();

            if ($result->failed()) {
                // Recorded before throwing. A scheduled clean swallows the
                // exception so one bad category cannot stop the tick, which
                // used to mean a cleaner failing every night looked exactly
                // like one that had never run — and the `failed` status the
                // run table defines was never written by anything.
                DiskCleanerRun::create([
                    'trigger' => $trigger,
                    'categories' => $keys,
                    'freed' => collect($cleaned)->pluck('freed', 'key')->all(),
                    'freed_total' => $freedTotal,
                    'status' => 'failed',
                    'disk_percent' => $this->cleaner->disk()['percent'],
                ]);

                $this->activityLogger->log($this->verb($trigger, failed: true), null, [
                    'categories' => implode(', ', $keys),
                    'category' => $key,
                ]);

                throw new DiskCleanerException($result->reference);
            }

            $freed = max(0, $before - $target->estimate());
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
            'freed' => collect($cleaned)->pluck('freed', 'key')->all(),
            'freed_total' => $freedTotal,
            'status' => 'completed',
            'disk_percent' => $disk['percent'],
        ]);

        $this->activityLogger->log($this->verb($trigger), null, [
            'categories' => implode(', ', $keys),
            'freed' => Bytes::human($freedTotal),
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
     * A separate verb rather than a `trigger` property, because the activity
     * filters are built from the lang keys and filter on the indexed `action`
     * column — a property would only be visible by opening each row, while a
     * verb becomes something you can actually filter the page by.
     *
     * It also lets the automatic sentence stand on its own. A scheduled run has
     * no actor, and "Cleaned disk" next to an empty name reads as though we had
     * lost track of who did it.
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
