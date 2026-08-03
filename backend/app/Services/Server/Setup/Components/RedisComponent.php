<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Settings\RedisSettings;

/**
 * Redis, which the panel uses for its own cache.
 *
 * The installer puts it in, so on a server built by this project it is always
 * present. It is listed because of the other case: a server migrated in from
 * elsewhere may not have it, and the panel then runs on the database cache — which
 * works, and is slower. The row makes that visible instead of silent.
 *
 * `action()` is null on purpose. There is no install endpoint for Redis, and
 * inventing one would mean the panel rewriting its own `.env` and restarting its
 * own cache mid-request — the credential-rotation dance that already needed a
 * terminating callback to get right. Until that is built properly, the honest
 * answer is "not from here".
 */
class RedisComponent implements SetupComponent
{
    public function __construct(private RedisSettings $redis) {}

    public function key(): string
    {
        return 'redis';
    }

    public function installed(): bool
    {
        return $this->redis->available();
    }

    public function recommended(): bool
    {
        return false;
    }

    public function detail(): ?string
    {
        return $this->installed() && config('cache.default') === 'redis'
            ? __('setup.detail.cache_in_use')
            : null;
    }

    public function action(): ?array
    {
        return null;
    }

    public function options(): array
    {
        return [];
    }
}
