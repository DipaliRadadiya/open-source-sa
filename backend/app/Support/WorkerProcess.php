<?php

namespace App\Support;

/**
 * "Is the process that started this still the one running?" — asked of a
 * backup whose worker may have been killed.
 *
 * Errs towards alive. The only answers that count as gone are the kernel
 * saying no such process (ESRCH), or a process under that PID that started
 * at a different moment (the PID was recycled). A process the panel may not
 * signal or read (another user, /proc mounted with hidepid) is alive, because
 * calling a live backup dead frees its lock and lets a second one start
 * alongside it — the outcome the lock exists to prevent.
 */
class WorkerProcess
{
    /** ESRCH: no such process. */
    private const NO_SUCH_PROCESS = 3;

    public static function pid(): int
    {
        return (int) getmypid();
    }

    /** This process's start time, in clock ticks since boot; null if /proc cannot say. */
    public static function startTime(?int $pid = null): ?string
    {
        $stat = @file_get_contents('/proc/'.($pid ?? self::pid()).'/stat');

        if (! is_string($stat) || ($close = strrpos($stat, ')')) === false) {
            return null;
        }

        // After "pid (comm)" come fields 3..; starttime is field 22.
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));

        return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : null;
    }

    public static function gone(int $pid, ?string $started): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return false;
        }

        if (! @posix_kill($pid, 0)) {
            return posix_get_last_error() === self::NO_SUCH_PROCESS;
        }

        if ($started === null) {
            return false;
        }

        $now = self::startTime($pid);

        return $now !== null && $now !== $started;
    }
}
