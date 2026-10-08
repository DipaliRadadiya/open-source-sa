<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived memory for read-only screens that ask the server (FS-C46).
 *
 * `GET /settings`, `/server/facts` and `/databases/engines` ran their server
 * commands on every load — 0.8–1.9 s each, measured on a test server — and
 * the Dashboard, Settings, Databases and Create screens each waited on one.
 * Their answers change when the panel changes them, so every such change
 * calls flush(); the TTL only bounds how long a change made *outside* the
 * panel (on the box by hand) takes to show.
 *
 * Generation-based rather than per-key forgets: the cache store has no tags
 * on every driver the panel supports, and one action (an install) changes
 * several answers at once.
 */
class ProbeCache
{
    private const GENERATION = 'probe-cache:generation';

    /**
     * @template T
     *
     * @param  Closure(): T  $probe
     * @return T
     */
    public static function remember(string $key, Closure $probe): mixed
    {
        $seconds = (int) config('server.probe_cache_seconds', 30);

        if ($seconds <= 0) {
            return $probe();
        }

        $generation = (int) Cache::get(self::GENERATION, 0);

        return Cache::remember("probe-cache:{$generation}:{$key}", $seconds, $probe);
    }

    /** Forget every cached answer — something the panel did changed one. */
    public static function flush(): void
    {
        Cache::forever(self::GENERATION, (int) Cache::get(self::GENERATION, 0) + 1);
    }
}
