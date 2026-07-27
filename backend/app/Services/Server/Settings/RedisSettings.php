<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ServerOps;

/**
 * Redis memory + auth settings via redis-cli CONFIG SET/REWRITE. Detect-gated
 * (only surfaces when redis-cli is present). The password is never returned —
 * only whether one is set.
 */
class RedisSettings implements SettingGroup
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'redis';
    }

    public function available(): bool
    {
        return is_file((string) config('server.redis_cli', '/usr/bin/redis-cli'));
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return [
            'maxmemory' => $this->configGet('maxmemory') ?: '0',
            'maxmemory_policy' => $this->configGet('maxmemory-policy') ?: 'noeviction',
            'has_password' => $this->configGet('requirepass') !== '',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $this->configSet('maxmemory', (string) $data['maxmemory']);
        $this->configSet('maxmemory-policy', (string) $data['maxmemory_policy']);

        // Password is optional — only change it when a new one is provided.
        if (! empty($data['password'])) {
            $this->configSet('requirepass', (string) $data['password']);
        }

        $this->run(['config', 'rewrite']); // persist to redis.conf
    }

    private function cli(): string
    {
        return (string) config('server.redis_cli', '/usr/bin/redis-cli');
    }

    private function configGet(string $key): string
    {
        // `CONFIG GET k` returns two lines: the key then the value.
        $output = $this->serverOps->run(
            [$this->cli(), 'config', 'get', $key],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'read', 'key' => $key],
        )->output();

        $lines = preg_split('/\r?\n/', trim($output)) ?: [];

        return isset($lines[1]) ? trim($lines[1]) : '';
    }

    private function configSet(string $key, string $value): void
    {
        $this->run(['config', 'set', $key, $value]);
    }

    /**
     * @param  array<int, string>  $args  redis-cli arguments (without the binary)
     */
    private function run(array $args): void
    {
        $result = $this->serverOps->run(
            [$this->cli(), ...$args],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'apply'],
        );

        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }
}
