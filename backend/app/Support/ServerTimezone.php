<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The timezone the operating system runs in — which is the one cron schedules
 * are interpreted in, and therefore the only correct basis for "when does this
 * job run next".
 *
 * Deliberately a filesystem read rather than `timedatectl`: this is called once
 * per cron job in a list response, and spawning a process per row would make a
 * cheap endpoint expensive. Memoized per request on top of that.
 */
class ServerTimezone
{
    private static ?string $cached = null;

    public static function get(): string
    {
        return self::$cached ??= self::detect();
    }

    /**
     * Tests (and anything that changes the server timezone) reset the memo.
     */
    public static function forget(): void
    {
        self::$cached = null;
    }

    /**
     * `/etc/localtime` first, because it is what the system clock — and so
     * cron — actually uses. `timedatectl set-timezone` repoints that link and
     * on Ubuntu 24.04 leaves `/etc/timezone` as it was (RC-1, measured
     * 2026-10-20): read first, the stale file kept every cron job saying
     * "Etc/UTC" while it fired 5½ hours earlier in Asia/Kolkata.
     *
     * `/etc/timezone` stays as the fallback for systems where the link is a
     * plain copy of the zone file (some containers), which names no zone.
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
