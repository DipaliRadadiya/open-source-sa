<?php

namespace App\Services\Server\DiskCleaner;

use Illuminate\Support\Facades\Date;

/**
 * The one "keep" choice a clean runs with, shared by every category.
 *
 * `0` deletes everything the category covers; `N` keeps whatever is newer
 * than N days. `null` is not a choice the user makes — it is a request or a
 * schedule saved before the choice existed, and each category answers it with
 * exactly what it did then, so no existing server changes behaviour until
 * somebody picks a value.
 *
 * A fixed list rather than any integer: the preview prices every choice up
 * front, and an open number would make that list unbounded.
 */
final class KeepDays
{
    public const DELETE_ALL = 0;

    /** @var array<int, int> */
    public const CHOICES = [0, 1, 3, 7, 14, 30];

    /** The choice the screen recommends. */
    public const RECOMMENDED = 7;

    /** How a clean with no choice is recorded in the activity log. */
    public const UNCHOSEN = 'default';

    /**
     * The choice in words, in the current locale. Takes what the activity log
     * stored — a choice, {@see UNCHOSEN}, or nothing at all on rows written
     * before the choice existed.
     */
    public static function label(int|string|null $keepDays): string
    {
        return match (true) {
            ! is_numeric($keepDays) => __('disk-cleaner.keep_days.default'),
            (int) $keepDays === self::DELETE_ALL => __('disk-cleaner.keep_days.delete_all'),
            default => trans_choice('disk-cleaner.keep_days.keep', (int) $keepDays, ['days' => (int) $keepDays]),
        };
    }

    /**
     * The newest modification time (unix seconds) a file may have and still be
     * removed under this choice. Delete all has no cutoff.
     */
    public static function cutoff(int $days): ?int
    {
        return $days === self::DELETE_ALL ? null : Date::now()->getTimestamp() - $days * 86400;
    }

    /**
     * `find` arguments selecting files last changed more than `$days` ago.
     * Minutes, not `-mtime`: `-mtime +7` rounds down to whole days and means
     * "8 days or more", which is not what "keep 7 days" says.
     *
     * @return array<int, string>
     */
    public static function findAge(int $days, string $test = '-mmin'): array
    {
        return $days === self::DELETE_ALL ? [] : [$test, '+'.($days * 1440)];
    }
}
