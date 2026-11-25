<?php

namespace App\Services\Server\DiskCleaner;

use Illuminate\Support\Facades\Date;

/**
 * The one "keep" choice a clean runs with, shared by every category.
 *
 * `0` deletes everything the category covers; `N` keeps whatever is newer than N days. `null` is
 * a request or schedule saved before the choice existed: each category answers it as it did then.
 *
 * A fixed list rather than any integer: the preview prices every choice up front.
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
     * The choice in words, in the current locale. Takes what the activity log stored: a choice,
     * {@see UNCHOSEN}, or nothing on older rows.
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
     * The newest modification time (unix seconds) a file may have and still be removed.
     * Delete all has no cutoff.
     */
    public static function cutoff(int $days): ?int
    {
        return $days === self::DELETE_ALL ? null : Date::now()->getTimestamp() - $days * 86400;
    }

    /**
     * `find` arguments selecting files last changed more than `$days` ago. Minutes, not `-mtime`:
     * `-mtime +7` means "8 days or more", not "keep 7 days".
     *
     * @return array<int, string>
     */
    public static function findAge(int $days, string $test = '-mmin'): array
    {
        return $days === self::DELETE_ALL ? [] : [$test, '+'.($days * 1440)];
    }
}
