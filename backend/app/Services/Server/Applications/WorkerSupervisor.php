<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Worker;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * Runs an application's background workers, via systemd template units.
 *
 * A sibling of ProcessSupervisor rather than an extension of it: that one
 * supervises *the* application process, exactly one, whose command lives on
 * the application row. A worker is one of many, has its own row, and can be
 * asked for in multiples — which systemd expresses as a template unit
 * (`sv-worker-3@.service`) instantiated per copy (`@1`, `@2`, …).
 *
 * The multiple is why the instances are systemd's and not ours. Storing four
 * rows for "four copies of the same worker" would make the panel responsible
 * for keeping them in step with what is actually running, which is the
 * bookkeeping this design avoids everywhere else.
 */
class WorkerSupervisor
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private FrameworkDetector $frameworks,
    ) {}

    /** `sv-worker-3@.service` — the template. */
    public function template(Worker $worker): string
    {
        return 'sv-worker-'.$worker->id.'@.service';
    }

    /** `sv-worker-3@2.service` — one instance of it. */
    public function instance(Worker $worker, int $number): string
    {
        return 'sv-worker-'.$worker->id.'@'.$number.'.service';
    }

    public function unitPath(Worker $worker): string
    {
        $dir = rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/');

        return $dir.'/'.$this->template($worker);
    }

    /**
     * Write the unit and bring the requested number of copies up.
     *
     * Verified with `is-active` afterwards, for the reason the application
     * supervisor documents: `systemctl start` succeeds for a unit that starts
     * and dies immediately, which is exactly what a mistyped command does. A
     * panel that reported such a worker as running would be worse than one
     * that refused it.
     *
     * @throws ProvisioningFailedException
     */
    public function apply(Worker $worker): void
    {
        $context = $this->context($worker, 'worker_write');

        $written = $this->files->put($this->unitPath($worker), $this->render($worker), $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_unit', $written->reference);
        }

        $this->daemonReload();

        // Instances beyond the new count are stopped first: lowering
        // `processes` from 4 to 2 must actually stop two of them, and nothing
        // else in the system would ever notice they were still running.
        $this->stopSurplus($worker);

        if (! $worker->enabled) {
            $this->stop($worker);

            return;
        }

        foreach ($this->instances($worker) as $unit) {
            $this->systemctl('enable', $unit, $worker);

            $started = $this->systemctl('restart', $unit, $worker);

            if ($started->failed() || ! $this->activeInstance($unit, $worker)) {
                $reference = $started->reference;
                $this->remove($worker);

                throw new ProvisioningFailedException('start_worker', $reference);
            }
        }
    }

    /** Stop, disable and forget every instance, then delete the template. */
    public function remove(Worker $worker): void
    {
        $this->stop($worker);

        foreach ($this->instances($worker, self::MAX_TRACKED) as $unit) {
            $this->systemctl('disable', $unit, $worker);
        }

        $this->files->delete($this->unitPath($worker), $this->context($worker, 'worker_remove'));
        $this->daemonReload();
    }

    public function start(Worker $worker): void
    {
        foreach ($this->instances($worker) as $unit) {
            $this->systemctl('start', $unit, $worker);
        }
    }

    public function stop(Worker $worker): void
    {
        // Every instance we might ever have started, not just the current
        // count: an instance left running after `processes` was lowered is
        // invisible to the panel and would keep consuming the queue.
        foreach ($this->instances($worker, self::MAX_TRACKED) as $unit) {
            $this->systemctl('stop', $unit, $worker);
        }
    }

    /**
     * Restart, in the way this kind of worker is meant to be restarted.
     *
     * A Laravel queue worker is told to finish its current job and exit
     * (`queue:restart`); systemd then starts it again with the new code. That
     * is gentler than restarting the unit, which can kill a job mid-flight.
     * Horizon has its own equivalent. Anything else has no such protocol, so
     * the unit is restarted directly.
     */
    public function restart(Worker $worker): void
    {
        $graceful = $this->gracefulRestartCommand($worker);

        if ($graceful !== null && $this->serverOps->run(
            $graceful,
            $this->context($worker, 'worker_graceful_restart'),
            timeout: 60,
        )->ok) {
            return;
        }

        foreach ($this->instances($worker) as $unit) {
            $this->systemctl('restart', $unit, $worker);
        }
    }

    /**
     * How many copies are actually up, right now.
     *
     * Reported as "running of requested" rather than a single boolean: a
     * worker pool with three of four processes alive is a real state, it is
     * easy to miss, and a green dot would hide it.
     *
     * @return array{running: int, requested: int, state: string}
     */
    public function status(Worker $worker): array
    {
        $running = 0;

        foreach ($this->instances($worker) as $unit) {
            if ($this->activeInstance($unit, $worker)) {
                $running++;
            }
        }

        return [
            'running' => $running,
            'requested' => $worker->processes,
            'state' => match (true) {
                $running === 0 => 'stopped',
                $running < $worker->processes => 'degraded',
                default => 'running',
            },
        ];
    }

    /**
     * The command that makes a running worker pick up new code, or null when
     * this kind has no such protocol.
     *
     * @return array<int, string>|null
     */
    public function gracefulRestartCommand(Worker $worker): ?array
    {
        $application = $worker->application;
        $root = $this->directory($worker);
        $php = 'php'.($application->php_version ?: '');

        if (! in_array($worker->kind, [Worker::KIND_QUEUE, Worker::KIND_HORIZON], true)) {
            return null;
        }

        // Craft has a queue, but not Laravel's `artisan queue:restart`. Let
        // systemd restart its unit directly instead of logging an expected
        // missing-command failure before doing that anyway.
        if ($this->frameworks->detect($application) === FrameworkDetector::CRAFT) {
            return null;
        }

        return match ($worker->kind) {
            Worker::KIND_QUEUE => [$php, $root.'/artisan', 'queue:restart'],
            Worker::KIND_HORIZON => [$php, $root.'/artisan', 'horizon:terminate'],
        };
    }

    /** Instances beyond the requested count, stopped and disabled. */
    private function stopSurplus(Worker $worker): void
    {
        for ($number = $worker->processes + 1; $number <= self::MAX_TRACKED; $number++) {
            $unit = $this->instance($worker, $number);

            if (! $this->activeInstance($unit, $worker)) {
                continue;
            }

            $this->systemctl('stop', $unit, $worker);
            $this->systemctl('disable', $unit, $worker);
        }
    }

    /**
     * @return array<int, string>
     */
    private function instances(Worker $worker, ?int $count = null): array
    {
        $count = $count ?? $worker->processes;

        return array_map(
            fn (int $number): string => $this->instance($worker, $number),
            range(1, max(1, $count)),
        );
    }

    private function activeInstance(string $unit, Worker $worker): bool
    {
        return $this->serverOps->run(
            ['systemctl', 'is-active', '--quiet', $unit],
            $this->context($worker, 'worker_is_active'),
            timeout: 15,
        )->ok;
    }

    private function systemctl(string $verb, string $unit, Worker $worker): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', $verb, $unit],
            $this->context($worker, 'worker_'.$verb),
            timeout: 120,
        );
    }

    private function daemonReload(): ServerOpsResult
    {
        return $this->serverOps->run(
            ['systemctl', 'daemon-reload'],
            ['feature' => 'application', 'op' => 'worker_daemon_reload'],
            timeout: 60,
        );
    }

    private function render(Worker $worker): string
    {
        $application = $worker->application;
        $projectRoot = $this->frameworks->root($application);

        return View::make('server.units.worker', [
            'worker' => $worker,
            'application' => $application,
            'user' => $application->systemUser->username,
            'projectRoot' => $projectRoot,
            'directory' => $worker->directory ?: $projectRoot,
            'exec' => $this->execStart($worker),
            'path' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'stopWaitSeconds' => $worker->stop_wait_seconds,
            'autoRestart' => $worker->auto_restart,
        ])->render();
    }

    private function directory(Worker $worker): string
    {
        return $worker->directory ?: $this->frameworks->root($worker->application);
    }

    /**
     * `ExecStart` is not a shell — systemd execs the binary directly, so a
     * bare `php` would never be found. The command is validated to a plain
     * `binary arg arg` form upstream; this only resolves the binary.
     */
    private function execStart(Worker $worker): string
    {
        $parts = preg_split('/\s+/', trim($worker->command)) ?: [];
        $binary = array_shift($parts) ?? '';

        if (! str_starts_with($binary, '/') && str_starts_with($binary, 'php')) {
            $binary = '/usr/bin/'.$binary;
        }

        return trim($binary.' '.implode(' ', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Worker $worker, string $op): array
    {
        return [
            'feature' => 'application',
            'op' => $op,
            'application' => $worker->application_id,
            'worker' => $worker->id,
        ];
    }

    /**
     * The highest instance number the panel will ever stop or disable.
     *
     * Bounded because "stop everything that might be running" has to terminate
     * somewhere, and it must be comfortably above the validated maximum for
     * `processes` so lowering the count can never strand an instance.
     */
    private const MAX_TRACKED = 32;
}
