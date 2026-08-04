<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Models\Worker;

/**
 * Starting points for a new worker, chosen by what the site actually is.
 *
 * The empty state of this screen is the whole feature for most people: nobody
 * should have to remember `--sleep=3 --tries=3` to get a queue running. So the
 * presets carry the flags, and a custom command is one click further away
 * rather than the only option.
 */
class WorkerPresets
{
    public function __construct(
        private FrameworkDetector $detector,
        private ApplicationEnvironment $environment,
        private EnvironmentInspector $inspector,
    ) {}

    /**
     * @return array<int, array{key: string, kind: string, title: string, description: string, command: string}>
     */
    public function for(Application $application): array
    {
        $framework = $this->detector->detect($application);
        $php = 'php'.($application->php_version ?: '');

        $presets = [];

        if (in_array($framework, [FrameworkDetector::LARAVEL, FrameworkDetector::STATAMIC], true)) {
            $presets[] = $this->preset(
                'queue', Worker::KIND_QUEUE,
                "{$php} artisan queue:work --sleep=3 --tries=3 --max-time=3600",
            );

            // Offered alongside the queue worker, not instead of it: Horizon
            // is the right answer for some applications and absent from most.
            // The two are mutually exclusive and the request layer says so.
            $presets[] = $this->preset('horizon', Worker::KIND_HORIZON, "{$php} artisan horizon");
        }

        if ($framework === FrameworkDetector::CRAFT) {
            $presets[] = $this->preset('queue', Worker::KIND_QUEUE, "{$php} craft queue/listen");
        }

        $presets[] = $this->preset('custom', Worker::KIND_CUSTOM, '');

        return $presets;
    }

    /**
     * Things that will stop a worker doing what the user expects, found
     * before they hit them.
     *
     * @return array<int, array{code: string, severity: string, title: string, detail: string}>
     */
    public function checks(Application $application): array
    {
        $framework = $this->detector->detect($application);

        if (! in_array($framework, [FrameworkDetector::LARAVEL, FrameworkDetector::STATAMIC], true)) {
            return [];
        }

        $checks = [];

        // The find that justifies this whole check list. `queue:restart` works
        // by leaving a flag in the cache for running workers to read; on the
        // `array` driver that cache does not survive the process that wrote
        // it, so the command succeeds, prints nothing, and no worker ever
        // restarts. Deploys then run new code on the site and week-old code in
        // the queue, with no error anywhere to connect the two.
        $cache = $this->cacheDriver($application);

        if ($cache === 'array') {
            $checks[] = [
                'code' => 'cache_driver_array',
                'severity' => 'warning',
                'title' => __('worker.checks.cache_driver_array.title'),
                'detail' => __('worker.checks.cache_driver_array.detail'),
            ];
        }

        return $checks;
    }

    private function cacheDriver(Application $application): ?string
    {
        if (! $this->environment->exists($application)) {
            return null;
        }

        $values = [];

        foreach ($this->inspector->variables($this->environment->read($application)) as $variable) {
            $values[$variable['key']] = $variable['value'];
        }

        // CACHE_STORE is the Laravel 11+ name; CACHE_DRIVER is what older
        // applications still use, and plenty of deployed sites are older.
        $driver = $values['CACHE_STORE'] ?? $values['CACHE_DRIVER'] ?? null;

        return $driver === null ? null : strtolower(trim($driver));
    }

    /**
     * @return array{key: string, kind: string, title: string, description: string, command: string}
     */
    private function preset(string $key, string $kind, string $command): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'title' => __("worker.presets.{$key}.title"),
            'description' => __("worker.presets.{$key}.description"),
            'command' => $command,
        ];
    }
}
