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
 * Harder than the other resources, and fuzzier: nothing on a server says
 * "this is a queue worker". What can be said is that a program's working
 * directory is inside a site the panel now knows about, which is enough to
 * attribute it, and that its command looks like a queue runner, which is
 * enough to guess its kind.
 *
 * Two sources, because migrated boxes use both: supervisor program blocks
 * (the convention every Laravel tutorial teaches) and systemd units.
 *
 * Everything adopted here lands **disabled**. A worker the panel starts while
 * the original is still running means two processes pulling the same queue,
 * and for anything touching money that is the failure that matters. The panel
 * records what exists; turning it on is a decision the user makes after
 * stopping the old one.
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
        // A worker belongs to a site. Before applications have run there is
        // nothing to attribute one to.
        return ['application'];
    }

    public function discover(SyncRun $run): array
    {
        $applications = Application::query()->with('systemUser')->get()
            // Longest root first, so a site nested inside another claims its
            // own workers rather than the parent swallowing them.
            ->sortByDesc(fn (Application $a): int => strlen($a->rootPath()))
            ->values();

        if ($applications->isEmpty()) {
            return [];
        }

        $tracked = Worker::query()->pluck('name', 'application_id');

        return array_merge(
            $this->fromSupervisor($applications, $tracked),
            $this->fromSystemd($applications, $tracked),
        );
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return Worker::firstOrCreate(
            [
                'application_id' => $attributes['application_id'],
                'name' => $attributes['name'],
            ],
            [
                'command' => $attributes['command'],
                'kind' => $attributes['kind'],
                'directory' => $attributes['directory'],
                'processes' => $attributes['processes'],
                // Disabled on purpose. See the class docblock: enabling this
                // while the original is still running puts two processes on
                // one queue.
                'enabled' => false,
            ],
        );
    }

    /**
     * `[program:name]` blocks under supervisor's conf.d.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, string>  $tracked
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
            $blocks = preg_split('/^\[program:([^\]]+)\]/mi', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);

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
                    ['source' => 'supervisor', 'path' => $path, 'program' => $name],
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
     * @param  Collection<int, string>  $tracked
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

            // The panel's own units. Their names encode a worker id from this
            // database, so either the row already exists or the id is
            // meaningless — adopting one would invent a worker whose unit the
            // panel would then write under a different name.
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
     * Attribution is by path, not by name: a program called `worker` says
     * nothing about which of forty sites it belongs to, while a working
     * directory inside one of them says exactly that. A program the panel
     * cannot place is not reported at all — the box is full of services that
     * have nothing to do with hosting, and listing every one of them would
     * bury the handful that matter.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, string>  $tracked
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
        // The working directory when there is one, otherwise wherever the
        // command itself points — plenty of units pass an absolute path to
        // artisan and set no WorkingDirectory at all.
        $haystack = $workingDir ?: $command;

        $application = $applications->first(function (Application $candidate) use ($haystack): bool {
            $root = rtrim($candidate->rootPath(), '/');

            if ($root === '') {
                return false;
            }

            // The directory *is* the site root in the common case
            // (`directory=/home/user/shop`), so requiring a trailing slash
            // matched nothing at all — while a bare `str_contains($root)`
            // would let `/home/user/shop` claim `/home/user/shopping`.
            return $haystack === $root || str_contains($haystack, $root.'/');
        });

        if ($application === null) {
            return null;
        }

        if (($tracked[$application->id] ?? null) === $name) {
            return null;
        }

        $kind = $this->kind($command);

        return [
            'key' => $application->domain.':'.$name,
            'label' => $name,
            // Attribution is a fact (the path is inside the site); the kind is
            // read off a command line and is not.
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
     * It decides how the panel restarts it — Horizon wants
     * `horizon:terminate`, a queue worker wants `queue:restart`, and anything
     * else can only be bounced. Guessing wrong means a restart that silently
     * does not restart.
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
