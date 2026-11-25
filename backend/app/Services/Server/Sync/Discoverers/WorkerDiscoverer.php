<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\Application;
use App\Models\SyncRun;
use App\Models\Worker;
use App\Services\Server\ServerOps;
use Illuminate\Support\Collection;

/**
 * Background processes a migrated site is already running.
 *
 * Nothing on a server says "this is a queue worker", so a program is attributed by its working
 * directory being inside a known site, and its kind is guessed from the command line.
 *
 * Two sources: supervisor program blocks and systemd units.
 *
 * Everything adopted here lands **disabled**: starting a panel worker while the original still
 * runs puts two processes on the same queue. The user enables it after stopping the old one.
 */
class WorkerDiscoverer implements Discoverable
{
    public function __construct(private ServerOps $serverOps) {}

    public function resourceType(): string
    {
        return 'worker';
    }

    public function dependsOn(): array
    {
        // A worker belongs to a site; without applications there is nothing to attribute it to.
        return ['application'];
    }

    public function discover(SyncRun $run): array
    {
        $applications = Application::query()->with('systemUser')->get()
            // Longest root first, so a nested site claims its own workers.
            ->sortByDesc(fn (Application $a): int => strlen($a->rootPath()))
            ->values();

        if ($applications->isEmpty()) {
            return [];
        }

        $tracked = Worker::query()->get()->groupBy('application_id');

        return array_merge(
            $this->fromSupervisor($applications, $tracked),
            $this->fromSystemd($applications, $tracked),
        );
    }

    /** @param array{key?: string, attributes?: array<string, mixed>, evidence?: array<string, mixed>} $item */
    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        $worker = Worker::firstOrCreate(
            [
                'application_id' => $attributes['application_id'],
                'name' => $attributes['name'],
            ],
            [
                'command' => $attributes['command'],
                'kind' => $attributes['kind'],
                'directory' => $attributes['directory'],
                'processes' => $attributes['processes'],
                // Disabled on purpose; see the class docblock.
                'enabled' => false,
            ],
        );

        // Only a single canonical v7 file can be controlled in place. Never delete a shared
        // config when one of its groups is saved.
        $evidence = $item['evidence'] ?? [];
        $program = $evidence['program'] ?? '';
        $directory = rtrim((string) config('server.applications.supervisor_dir', '/etc/supervisor/conf.d'), '/');
        if ($worker->wasRecentlyCreated && ($evidence['source'] ?? null) === 'supervisor'
            && ($evidence['program_count'] ?? 0) === 1
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $program)
            && ($evidence['path'] ?? null) === $directory.'/'.$program.'.conf') {
            $worker->forceFill(['program' => $program])->save();
        }

        return $worker;
    }

    /**
     * `[program:name]` blocks under supervisor's conf.d.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, Worker>>  $tracked
     * @return array<int, array<string, mixed>>
     */
    private function fromSupervisor($applications, $tracked): array
    {
        $directory = rtrim((string) config('server.applications.supervisor_dir', '/etc/supervisor/conf.d'), '/');
        $found = [];

        foreach ($this->files($directory) as $path) {
            $contents = $this->read($path);

            if ($contents === null) {
                continue;
            }

            // Split on the program headers, keeping each block with its name.
            $blocks = preg_split('/^[ \t]*\[program:([^\]]+)\]/mi', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);

            for ($i = 1; $i < count($blocks); $i += 2) {
                $name = trim($blocks[$i]);
                $body = $blocks[$i + 1] ?? '';

                $command = $this->ini($body, 'command');
                $workingDir = $this->ini($body, 'directory');

                if ($command === null) {
                    continue;
                }

                $item = $this->attribute(
                    $applications,
                    $tracked,
                    $name,
                    $command,
                    $workingDir,
                    (int) ($this->ini($body, 'numprocs') ?? 1),
                    ['source' => 'supervisor', 'path' => $path, 'program' => $name, 'program_count' => intdiv(count($blocks) - 1, 2)],
                );

                if ($item !== null) {
                    $found[] = $item;
                }
            }
        }

        return $found;
    }

    /**
     * systemd units whose ExecStart runs inside a site the panel knows.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, Worker>>  $tracked
     * @return array<int, array<string, mixed>>
     */
    private function fromSystemd($applications, $tracked): array
    {
        $directory = rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/');
        $found = [];

        foreach ($this->files($directory) as $path) {
            if (! str_ends_with($path, '.service')) {
                continue;
            }

            $name = basename($path, '.service');

            // The panel's own units: their names encode a worker id from this database, and
            // adopting one would invent a worker whose unit the panel writes under another name.
            if (str_starts_with($name, 'sv-worker-') || str_starts_with($name, 'sv-app-')) {
                continue;
            }

            $contents = $this->read($path);

            if ($contents === null) {
                continue;
            }

            $command = $this->ini($contents, 'ExecStart');
            $workingDir = $this->ini($contents, 'WorkingDirectory');

            if ($command === null) {
                continue;
            }

            $item = $this->attribute(
                $applications,
                $tracked,
                rtrim($name, '@'),
                $command,
                $workingDir,
                1,
                ['source' => 'systemd', 'path' => $path, 'unit' => basename($path)],
            );

            if ($item !== null) {
                $found[] = $item;
            }
        }

        return $found;
    }

    /**
     * Tie one program to a site, or leave it alone.
     *
     * Attribution is by path, not by name: `worker` says nothing about which site it belongs
     * to. A program the panel cannot place is not reported; the box is full of unrelated services.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, Worker>>  $tracked
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>|null
     */
    private function attribute(
        $applications,
        $tracked,
        string $name,
        string $command,
        ?string $workingDir,
        int $processes,
        array $evidence,
    ): ?array {
        // The working directory, else wherever the command points: many units pass an absolute
        // path to artisan and set no WorkingDirectory.
        $haystack = $workingDir ?: $command;

        $application = $applications->first(function (Application $candidate) use ($haystack): bool {
            $root = rtrim($candidate->rootPath(), '/');

            if ($root === '') {
                return false;
            }

            // The directory is often the site root itself (`directory=/home/user/shop`), while a
            // bare `str_contains($root)` would let `/home/user/shop` claim `/home/user/shopping`.
            return $haystack === $root || str_contains($haystack, $root.'/');
        });

        if ($application === null) {
            return null;
        }

        // A site without a worker screen (static, Docker, marketplace PHP) cannot own a worker:
        // nothing could edit or remove it. Asked of the feature, not `serving_profile`, because
        // a git site rendered static still has workers.
        if (! $application->supports('app_worker')) {
            return null;
        }

        if (($tracked[$application->id] ?? collect())->contains(fn (Worker $worker) => $worker->name === $name || $worker->program === $name
            || ($worker->program === null && 'sv-worker-'.$worker->slug === $name))) {
            return null;
        }

        $kind = $this->kind($command);

        return [
            'key' => $application->domain.':'.$name,
            'label' => $name,
            // Attribution is a fact (path inside the site); the kind is only guessed.
            'confidence' => $kind === 'custom' ? 50 : 90,
            'evidence' => $evidence + [
                'application' => $application->domain,
                'command' => $command,
                'kind' => $kind,
                'processes' => $processes,
                // The thing the user has to act on before enabling this.
                'already_running_elsewhere' => true,
            ],
            'attributes' => [
                'application_id' => $application->id,
                'name' => $name,
                'command' => $command,
                'kind' => $kind,
                'directory' => $workingDir,
                'processes' => max(1, $processes),
            ],
        ];
    }

    /**
     * What sort of worker this is, judged by the command.
     *
     * It decides how the panel restarts it: Horizon wants `horizon:terminate`, a queue worker
     * `queue:restart`, anything else is bounced. A wrong guess means a restart that does nothing.
     */
    private function kind(string $command): string
    {
        return match (true) {
            str_contains($command, 'horizon') => 'horizon',
            str_contains($command, 'queue:work'), str_contains($command, 'queue:listen') => 'queue',
            default => 'custom',
        };
    }

    /** @return array<int, string> */
    private function files(string $directory): array
    {
        $listing = $this->serverOps->run(
            ['find', $directory, '-maxdepth', '1', '-type', 'f'],
            ['feature' => 'sync', 'op' => 'discover_workers'],
            timeout: 30,
        );

        if ($listing->failed()) {
            // No supervisor installed, or no systemd directory. Normal.
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($listing->output())) ?: []),
            fn (string $path): bool => $path !== '',
        ));
    }

    private function read(string $path): ?string
    {
        $result = $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'sync', 'op' => 'read_worker_definition'],
            timeout: 30,
        );

        return $result->failed() ? null : $result->output();
    }

    /** `key=value` or `Key = value`, first occurrence, quotes stripped. */
    private function ini(string $contents, string $key): ?string
    {
        if (! preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+?)\s*$/mi', $contents, $matches)) {
            return null;
        }

        return trim($matches[1], " \t\"'") ?: null;
    }
}
