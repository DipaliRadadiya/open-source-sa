<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        private RebootScheduleSettings $rebootSchedule,
        private RedisSettings $redis,
        private MysqlSettings $mysql,
    ) {}

    /**
     * @return array<int, SettingGroup>
     */
    public function groups(): array
    {
        return [$this->general, $this->swap, $this->security, $this->updates, $this->rebootSchedule, $this->redis, $this->mysql];
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
     * Each group is isolated: one throwing (a bad `available()` probe, a
     * `read()` that hits an unexpected state) must not take the other five
     * down with it, and the response would otherwise look identical to every
     * group failing — the caller gets a full page failure to debug instead of
     * "the one group that changed recently is missing." Logged with the full
     * exception, because that gap is exactly what turned a real bug into a
     * silent one previously.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $settings = [];
        foreach ($this->groups() as $group) {
            try {
                if ($group->available()) {
                    $settings[$group->key()] = $group->read();
                }
            } catch (Throwable $e) {
                Log::error('settings group failed to read', [
                    'group' => $group->key(),
                    'exception' => $e,
                ]);
            }
        }

        return $settings;
    }
}
