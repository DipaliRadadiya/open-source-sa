<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;

/**
 * Aggregates the setting groups. `GET /settings` returns every available
 * group's current values; each group is detect-gated so unavailable ones
 * (e.g. redis when not installed) are simply omitted.
 */
class SettingsManager
{
    public function __construct(
        private GeneralSettings $general,
        private SwapSettings $swap,
        private SecuritySettings $security,
        private UpdateSettings $updates,
        private RedisSettings $redis,
    ) {}

    /**
     * @return array<int, SettingGroup>
     */
    public function groups(): array
    {
        return [$this->general, $this->swap, $this->security, $this->updates, $this->redis];
    }

    public function find(string $key): ?SettingGroup
    {
        foreach ($this->groups() as $group) {
            if ($group->key() === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Current values for every available group, keyed by group.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $settings = [];
        foreach ($this->groups() as $group) {
            if ($group->available()) {
                $settings[$group->key()] = $group->read();
            }
        }

        return $settings;
    }
}
