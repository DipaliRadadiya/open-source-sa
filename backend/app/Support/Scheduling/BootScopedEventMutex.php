<?php

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;

/**
 * The scheduler's "not twice at once" lock, named for the current boot.
 *
 * A server that goes down mid-run never removes the `withoutOverlapping()` lock, and the Redis
 * cache saves to disk, so the lock returns with the server and blocks that command until it
 * expires (24 hours by default).
 *
 * Nothing can be running from before a reboot, so such a lock is always stale. Putting the
 * kernel's boot ID in the name makes it stop matching; no boot hook is needed.
 *
 * A crash without a reboot (PHP process killed) is covered by the per-command expiry in
 * routes/console.php.
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
     * Run a parent method with the lock name suffixed by the boot ID, then restore the resolver.
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
