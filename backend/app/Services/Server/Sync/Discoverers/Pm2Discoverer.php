<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Enums\SupervisorMode;
use App\Models\Application;
use App\Models\SyncRun;
use App\Services\Server\Applications\LegacyPm2Driver;
use Illuminate\Support\Collection;

/**
 * Applications a migrated server is already running under the old panel's PM2.
 *
 * Unlike the other discoverers this marks records the panel already has: nothing on disk says
 * that a PM2 daemon keeps a vhost-discovered application alive. Without the mark the panel
 * would write a systemd unit for a port PM2 already holds. Runs after `application`; marked
 * applications route start, stop, restart, deploy and status through {@see LegacyPm2Driver}.
 *
 * **It changes nothing on the server, and neither does adopting.** These are running customer
 * sites that must not restart. Stopping the old agent, repairing boot persistence and log
 * rotation are server writes behind an explicit user action, not Sync.
 *
 * Matching is by working directory, longest root first, as in {@see WorkerDiscoverer}: a
 * process whose `pm_cwd` is inside a site is that site's; a nested site claims its own.
 */
class Pm2Discoverer implements Discoverable
{
    public function __construct(private LegacyPm2Driver $pm2) {}

    public function resourceType(): string
    {
        return 'pm2_process';
    }

    public function dependsOn(): array
    {
        // A PM2 process is attributed to a site by its working directory.
        return ['application'];
    }

    public function discover(SyncRun $run): array
    {
        $applications = Application::query()->with('systemUser')->get()
            ->filter(fn (Application $a): bool => $a->systemUser !== null)
            // No slug means `rootPath()` falls back to the user's home, so every process of
            // that account would match this one site. Left out to avoid swallowing neighbours.
            ->reject(fn (Application $a): bool => rtrim($a->rootPath(), '/') === rtrim((string) $a->systemUser->home_path, '/'))
            ->sortByDesc(fn (Application $a): int => strlen($a->rootPath()))
            ->values();

        if ($applications->isEmpty()) {
            return [];
        }

        $items = [];

        foreach ($applications->groupBy('system_user_id') as $owned) {
            $user = $owned->first()->systemUser;

            // One `jlist` per OS user, not per application: PM2's state is per user.
            $processes = $this->pm2->processesFor($user->username, (string) $user->home_path);

            if ($processes === null) {
                continue;
            }

            foreach ($this->group($processes) as $name => $instances) {
                $items[] = $this->item($name, $instances, $applications, $user->username);
            }
        }

        return array_values(array_filter($items));
    }

    /**
     * Create nothing; mark the existing application as PM2-supervised.
     */
    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        $application = Application::query()->find($attributes['application_id'] ?? null);

        if ($application === null) {
            return null;
        }

        $application->forceFill([
            'supervisor_mode' => SupervisorMode::Pm2,
            'pm2_process_name' => $attributes['process_name'],
            // What the daemon is actually running, not an assumed single process.
            'process_instances' => $attributes['instances'] > 1 ? $attributes['instances'] : null,
        ])->save();

        return $application;
    }

    /**
     * `jlist` returns one entry per worker; a clustered application appears
     * several times under one name.
     *
     * @param  array<int, mixed>  $processes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function group(array $processes): array
    {
        $grouped = [];

        foreach ($processes as $process) {
            if (! is_array($process) || ! filled($process['name'] ?? null)) {
                continue;
            }

            $grouped[(string) $process['name']][] = $process;
        }

        return $grouped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $instances
     * @param  Collection<int, Application>  $applications
     * @return array<string, mixed>|null
     */
    private function item(string $name, array $instances, $applications, string $username): ?array
    {
        $cwd = (string) ($instances[0]['pm2_env']['pm_cwd'] ?? '');
        $key = $username.':'.$name;

        $application = $cwd === '' ? null : $applications->first(
            fn (Application $a): bool => str_starts_with($cwd.'/', rtrim($a->rootPath(), '/').'/'),
        );

        // Not attributable to a known site: reported, never guessed at (the old agent and
        // customers run their own processes).
        if ($application === null) {
            return [
                'key' => $key,
                'label' => $name,
                'skip' => 'unmatched_directory',
                'evidence' => ['cwd' => $cwd, 'user' => $username],
            ];
        }

        if ($application->supervisor_mode === SupervisorMode::Pm2) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $application->name.' ('.$name.')',
            // The working directory is a fact, not an inference.
            'confidence' => 100,
            'evidence' => [
                'user' => $username,
                'cwd' => $cwd,
                'instances' => count($instances),
                'exec_mode' => $instances[0]['pm2_env']['exec_mode'] ?? null,
                'status' => $instances[0]['pm2_env']['status'] ?? null,
            ],
            'attributes' => [
                'application_id' => $application->id,
                'process_name' => $name,
                'instances' => count($instances),
            ],
        ];
    }
}
