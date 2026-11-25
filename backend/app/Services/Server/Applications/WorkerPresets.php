<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Models\Worker;

/**
 * Starting points for a new worker, chosen by what the site actually is.
 *
 * The presets carry the flags (`--sleep=3 --tries=3`) so nobody has to remember them; a custom
 * command stays one click away.
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

            // Alongside the queue worker, not instead of it: most apps do not have Horizon.
            // The two are mutually exclusive; the request layer enforces it.
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

        // `queue:restart` leaves a flag in the cache for workers to read; the `array` driver
        // loses it with the process, so the command succeeds but no worker ever restarts and
        // the queue keeps running old code after a deploy.
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

        // CACHE_STORE is the Laravel 11+ name; older applications still use CACHE_DRIVER.
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
