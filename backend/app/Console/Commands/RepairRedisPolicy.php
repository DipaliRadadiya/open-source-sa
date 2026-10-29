<?php

namespace App\Console\Commands;

use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\Settings\RedisSettings;
use Illuminate\Console\Command;

/**
 * Bring an existing server's Redis eviction policy in line with what a fresh
 * install now writes ({@see RedisSettings::repairInstallerPolicy()}).
 *
 * install.sh wrote `maxmemory-policy allkeys-lru`, which can evict the
 * panel's queued jobs and which the Redis screen refuses, so that screen could
 * not be saved on any server installed before the fix. Run by the panel's
 * updater. Idempotent; a failure reports and exits 1, and the updater carries
 * on (the old policy is what the server already had).
 */
class RepairRedisPolicy extends Command
{
    protected $signature = 'redis:repair-installer-policy';

    protected $description = 'Switch the installer\'s Redis allkeys-lru policy to volatile-lru, in redis.conf and live';

    public function handle(RedisSettings $redis): int
    {
        try {
            $result = $redis->repairInstallerPolicy();
        } catch (SettingOperationException $exception) {
            $this->error("Redis eviction policy not switched (reference {$exception->reference}).");

            return self::FAILURE;
        }

        $this->info(match ($result) {
            'changed' => 'Redis eviction policy switched from allkeys-lru to volatile-lru.',
            'unchanged' => 'Redis eviction policy is not the installer\'s allkeys-lru; nothing to change.',
            'unavailable' => 'Redis or its configuration is not readable here; nothing to change.',
        });

        return self::SUCCESS;
    }
}
