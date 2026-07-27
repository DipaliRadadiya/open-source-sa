<?php

namespace App\Actions\Server\DiskCleaner;

use App\Exceptions\Server\DiskCleaner\DiskCleanerException;
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
     * @return array<string, mixed>
     */
    public function execute(array $keys): array
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

        $this->activityLogger->log('disk_cleaner.cleaned', null, [
            'categories' => implode(', ', $keys),
            'freed' => Bytes::human($freedTotal),
        ]);

        return [
            'disk' => $this->cleaner->disk(),
            'cleaned' => $cleaned,
            'freed_total' => $freedTotal,
            'freed_total_human' => Bytes::human($freedTotal),
        ];
    }
}
