<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How long the activity log is kept, and the daily clean-up that enforces it
 * (2026-10-29, Dipali: the log grew forever).
 *
 * The choice is months, from a fixed list, or null for "keep everything" —
 * the default, so an update deletes nothing until someone chooses. It also
 * applies to the panel's own finished task runs (Disk Cleaner, security
 * updates, staging push/pull, add-ons), which grow the same way. Not to sync
 * runs, whose items point at them, nor to backups, restores, exports or
 * imports: those rows belong to real files and keep their own rules.
 *
 * Nothing from the last 30 days is ever removed, whatever is chosen.
 */
class ActivityRetention
{
    /** @var list<int> */
    public const CHOICES = [3, 6, 12, 24];

    private const FLOOR_DAYS = 30;

    /** Tables of finished runs swept with the log, and the statuses still in flight. */
    private const RUN_TABLES = [
        'disk_cleaner_runs' => [],
        'security_update_runs' => ['queued', 'pending', 'running'],
        'staging_runs' => ['queued', 'running'],
        'addon_runs' => ['queued', 'running'],
    ];

    public function months(): ?int
    {
        $value = DB::table('settings')->where('id', 1)->value('activity_retention_months');

        return in_array((int) $value, self::CHOICES, true) ? (int) $value : null;
    }

    public function set(?int $months): void
    {
        DB::table('settings')->updateOrInsert(
            ['id' => 1],
            ['activity_retention_months' => $months, 'updated_at' => now()],
        );
    }

    /**
     * What the screen shows beside the choice.
     *
     * @return array{entries: int, oldest_at: ?string}
     */
    public function stats(): array
    {
        $oldest = ActivityLog::query()->min('created_at');

        return [
            'entries' => ActivityLog::query()->count(),
            'oldest_at' => $oldest === null ? null : CarbonImmutable::parse($oldest)->format('d-m-Y H:i:s'),
        ];
    }

    /** Entries older than this go; null when nothing should. */
    public function cutoff(): ?CarbonImmutable
    {
        $months = $this->months();

        if ($months === null) {
            return null;
        }

        $cutoff = CarbonImmutable::now()->subMonthsNoOverflow($months);
        $floor = CarbonImmutable::now()->subDays(self::FLOOR_DAYS);

        return $cutoff->lessThan($floor) ? $cutoff : $floor;
    }

    /**
     * Delete what is older than the choice.
     *
     * @return array{activity: int, runs: int}
     */
    public function prune(): array
    {
        $cutoff = $this->cutoff();

        if ($cutoff === null) {
            return ['activity' => 0, 'runs' => 0];
        }

        $activity = 0;

        // In chunks: years of log on a small server is a lot of rows, and one
        // DELETE that long holds SQLite's write lock against the whole panel.
        do {
            $ids = ActivityLog::query()->where('created_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            $activity += $ids->isEmpty() ? 0 : ActivityLog::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $runs = 0;

        foreach (self::RUN_TABLES as $table => $inFlight) {
            $runs += DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->when($inFlight !== [], fn ($query) => $query->whereNotIn('status', $inFlight))
                ->delete();
        }

        return ['activity' => $activity, 'runs' => $runs];
    }
}
