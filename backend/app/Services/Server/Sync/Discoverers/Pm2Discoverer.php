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
 * The other discoverers find things the panel has no record of. This one finds
 * something about records it already has: an application discovered from its
 * vhost looks like any other site, and nothing on disk says that a PM2 daemon
 * is what keeps it alive. Getting that wrong is not cosmetic — the panel would
 * write a systemd unit for a port something else is already bound to, and the
 * first deploy would fail on a site that was working.
 *
 * So this runs after `application` and marks the ones it finds, which is what
 * routes every later start, stop, restart, deploy and status through
 * {@see LegacyPm2Driver} instead of `systemctl`.
 *
 * **It changes nothing on the server, and neither does adopting.** That is the
 * contract every discoverer keeps, and here it also happens to be the only safe
 * option: these are the customer's running sites, and the whole reason the
 * panel tolerates PM2 at all is that taking a server over must not restart
 * them. Three things the migration still needs — stopping the old agent,
 * repairing boot persistence, giving `~/.pm2/logs` a rotation policy it has
 * never had — are server writes and belong to an explicit action the user
 * triggers, not to pressing Sync.
 *
 * Matching is by working directory, longest root first, the same rule
 * {@see WorkerDiscoverer} uses: a process whose `pm_cwd` is inside a site is
 * that site's, and a site nested inside another claims its own.
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
        // Before applications have run there is nothing to attribute one to.
        return ['application'];
    }

    public function discover(SyncRun $run): array
    {
        $applications = Application::query()->with('systemUser')->get()
            ->filter(fn (Application $a): bool => $a->systemUser !== null)
            // A row with no slug has no directory of its own: `rootPath()`
            // falls back to the system user's home, so *every* process under
            // that account would look like it belongs to this one site. Left
            // out rather than allowed to swallow its neighbours — a site the
            // panel cannot locate is a site it cannot attribute anything to.
            ->reject(fn (Application $a): bool => rtrim($a->rootPath(), '/') === rtrim((string) $a->systemUser->home_path, '/'))
            ->sortByDesc(fn (Application $a): int => strlen($a->rootPath()))
            ->values();

        if ($applications->isEmpty()) {
            return [];
        }

        $items = [];

        foreach ($applications->groupBy('system_user_id') as $owned) {
            $user = $owned->first()->systemUser;

            // One `jlist` per OS user, not per application: PM2's state is per
            // user, and asking once per site would run the same command five
            // times on a server with five sites under one account.
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
     * Create nothing; mark what is already there.
     *
     * Unlike the other discoverers this adopts *into* an existing row. The
     * application was found from its vhost; what was missing was the knowledge
     * that a daemon rather than a unit is running it.
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
            // What the daemon is actually running, so the panel reports the
            // truth rather than the one process it would have assumed.
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

        // A process the panel cannot attribute to a site it knows about. Left
        // alone and reported rather than guessed at: the old panel's agent runs
        // its own things, and so do customers.
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
            // The working directory is a fact, not an inference — either the
            // process runs inside the site or it does not.
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
