<?php

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;

/**
 * The scheduler's "not twice at once" lock, named for the current boot.
 *
 * `withoutOverlapping()` stores a lock while a scheduled command runs and
 * removes it when the command ends. A server that goes down mid-run never
 * removes it — and the cache is Redis, which saves to disk, so the lock comes
 * back with the server and blocks that command until it expires: 24 hours by
 * default. Found on all three test servers on 2026-09-30, with nothing
 * running: scheduled backups had stopped on one, the hourly disk cleaner on
 * another, each for most of a day after an outage.
 *
 * Nothing can be running from before a reboot, so a lock from before one is
 * always stale. Putting the kernel's boot ID in the name makes those locks
 * simply not match any more; they expire on their own. No boot hook, and so
 * nothing for install.sh, the updater or the runbook to remember.
 *
 * A crash without a reboot (the PHP process killed) is covered separately, by
 * the per-command expiry in routes/console.php.
 */
class BootScopedEventMutex extends CacheEventMutex
{
    public function create(Event $event)
    {
        return $this->scoped($event, fn () => parent::create($event));
    }

    public function exists(Event $event)
    {
        return $this->scoped($event, fn () => parent::exists($event));
    }

    public function forget(Event $event)
    {
        return $this->scoped($event, fn () => parent::forget($event));
    }

    /**
     * Run a parent method with the event's lock name suffixed by the boot ID,
     * then put the event's own resolver back.
     */
    private function scoped(Event $event, callable $operation): mixed
    {
        $resolver = $event->mutexNameResolver;
        $name = $event->mutexName();
        $boot = self::bootId();

        $event->mutexNameResolver = fn () => $boot === '' ? $name : "{$name}@{$boot}";

        try {
            return $operation();
        } finally {
            $event->mutexNameResolver = $resolver;
        }
    }

    /**
     * Empty where the kernel does not publish one (not Linux, or /proc not
     * readable): the lock then behaves exactly as Laravel's own.
     */
    public static function bootId(): string
    {
        $path = (string) config('server.boot_id_path', '/proc/sys/kernel/random/boot_id');

        return is_readable($path) ? trim((string) @file_get_contents($path)) : '';
    }
}
