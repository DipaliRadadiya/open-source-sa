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

    private static function detect(): string
    {
        // Debian/Ubuntu keep the plain name here.
        $file = (string) config('server.timezone_file', '/etc/timezone');

        if (File::exists($file)) {
            $timezone = trim((string) File::get($file));

            if ($timezone !== '') {
                return $timezone;
            }
        }

        // Fallback: /etc/localtime is a symlink into the zoneinfo database.
        $link = (string) config('server.localtime_link', '/etc/localtime');

        if (is_link($link) && ($target = readlink($link)) !== false) {
            if (preg_match('#zoneinfo/(.+)$#', $target, $matches) === 1) {
                return $matches[1];
            }
        }

        return 'UTC';
    }
}
