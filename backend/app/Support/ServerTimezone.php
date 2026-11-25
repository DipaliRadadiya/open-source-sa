<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The timezone the operating system runs in: the one cron schedules are interpreted in, so the
 * only correct basis for "when does this job run next".
 *
 * A filesystem read rather than `timedatectl`: it is called once per cron job in a list
 * response, and a process per row would make a cheap endpoint expensive.
 *
 * Memoized against what it was read from (the link's target and the file's mtime), not for the
 * life of the process: the queue worker runs for days and would keep a stale zone after a
 * timezone change.
 */
class ServerTimezone
{
    /** @var array{0: string, 1: string}|null [what it was read from, the zone] */
    private static ?array $cached = null;

    public static function get(): string
    {
        $source = self::source();

        if (self::$cached === null || self::$cached[0] !== $source) {
            self::$cached = [$source, self::detect()];
        }

        return self::$cached[1];
    }

    /**
     * Tests (and anything that changes the server timezone) reset the memo.
     */
    public static function forget(): void
    {
        self::$cached = null;
    }

    /**
     * What a timezone change rewrites: the link (`timedatectl`) or the file.
     */
    private static function source(): string
    {
        $link = (string) config('server.localtime_link', '/etc/localtime');
        $file = (string) config('server.timezone_file', '/etc/timezone');

        // PHP's stat cache would otherwise answer from before the change.
        clearstatcache(true, $link);
        clearstatcache(true, $file);

        return (is_link($link) ? (string) readlink($link) : '').'|'.(@filemtime($file) ?: 0);
    }

    /**
     * `/etc/localtime` first, because it is what cron actually uses: `timedatectl set-timezone`
     * repoints that link and on Ubuntu 24.04 leaves `/etc/timezone` stale.
     *
     * `/etc/timezone` is the fallback where the link is a plain copy of the zone file (some
     * containers), which names no zone.
     */
    private static function detect(): string
    {
        $link = (string) config('server.localtime_link', '/etc/localtime');

        if (is_link($link) && ($target = readlink($link)) !== false) {
            if (preg_match('#zoneinfo/(?:posix/|right/)?(.+)$#', $target, $matches) === 1) {
                return $matches[1];
            }
        }

        // Debian/Ubuntu keep the plain name here.
        $file = (string) config('server.timezone_file', '/etc/timezone');

        if (File::exists($file)) {
            $timezone = trim((string) File::get($file));

            if ($timezone !== '') {
                return $timezone;
            }
        }

        return 'UTC';
    }
}
