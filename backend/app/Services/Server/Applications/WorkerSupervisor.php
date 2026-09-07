<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Worker;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

/**
 * Runs an application's background workers, as supervisord programs.
 *
 * A sibling of ProcessSupervisor rather than an extension of it: that one
 * supervises *the* application process, exactly one, whose command lives on
 * the application row, and it stays on systemd. A worker is one of many, has
 * its own row, and can be asked for in multiples.
 *
 * ## Why supervisord and not systemd
 *
 * This ran on systemd template units (`sv-worker-shop-queue@1`, `@2`, …) until
 * 2026-09-07. supervisord is what the commercial panel has always
 * used, so anyone arriving from it finds the same fields and the same
 * `[program:…]` blocks on the box — and it is what every Laravel queue
 * tutorial teaches, which is what a migrated server is already running.
 *
 * That last point is the one that pays for itself. `WorkerDiscoverer` already
 * *reads* `/etc/supervisor/conf.d` to adopt a migrated box's workers. Writing
 * the same format makes adopt-then-manage one format instead of a translation
 * between two, and an adopted worker can now be edited rather than only
 * recorded.
 *
 * supervisor also owns the copies itself, via `numprocs`. The panel stores the
 * number it asked for and nothing else — "3 of 4 running" stays a question
 * supervisord answers rather than one the panel caches. Lowering the count is
 * `update`'s job, which is simpler than the old template's surplus-instance
 * sweep: there is no instance number to strand.
 */
class WorkerSupervisor
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private FrameworkDetector $frameworks,
    ) {}

    /** `sv-worker-shop-queue` — the program name. */
    public function program(Worker $worker): string
    {
        // The slug rather than the id, so the name says what it is when read
        // on the box — and the slug rather than the *name*, so renaming a
        // worker relabels it without stopping and recreating a running
        // process.
        return 'sv-worker-'.$worker->slug;
    }

    /**
     * `sv-worker-shop-queue:*` — every process of the program.
     *
     * supervisorctl treats a `numprocs` program as a group, and the bare name
     * addresses nothing: `supervisorctl restart sv-worker-x` on a program with
     * copies answers "no such process". The `:*` is not decoration.
     */
    public function group(Worker $worker): string
    {
        return $this->program($worker).':*';
    }

    public function configPath(Worker $worker): string
    {
        $dir = rtrim((string) config('server.applications.supervisor_dir', '/etc/supervisor/conf.d'), '/');

        return $dir.'/'.$this->program($worker).'.conf';
    }

    /**
     * Write the program and bring the requested number of copies up.
     *
     * Verified with `status` afterwards, for the reason the application
     * supervisor documents: starting succeeds for a program that starts and
     * dies immediately, which is exactly what a mistyped command does. A panel
     * that reported such a worker as running would be worse than one that
     * refused it.
     *
     * @throws ProvisioningFailedException
     */
    public function apply(Worker $worker): void
    {
        $context = $this->context($worker, 'worker_write');

        $written = $this->files->put($this->configPath($worker), $this->render($worker), $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_unit', $written->reference);
        }

        // `reread` parses the files, `update` applies the difference — adding
        // the program, and retiring copies when `numprocs` went down. Both,
        // and in that order: `update` alone acts on a config supervisord has
        // not re-read, so a saved change appears to do nothing.
        $this->reload($worker);

        if (! $worker->enabled) {
            $this->stop($worker);

            return;
        }

        $started = $this->supervisorctl(['restart', $this->group($worker)], $worker, 'worker_start');

        if ($started->failed() || $this->status($worker)['running'] < 1) {
            $reference = $started->reference;
            $this->remove($worker);

            throw new ProvisioningFailedException('start_worker', $reference);
        }
    }

    /** Stop every copy, then delete the program and let supervisord forget it. */
    public function remove(Worker $worker): void
    {
        $this->stop($worker);

        $this->files->delete($this->configPath($worker), $this->context($worker, 'worker_remove'));

        // After the file is gone, so `update` removes the program rather than
        // reinstating it from a config that is still on disk.
        $this->reload($worker);
    }

    public function start(Worker $worker): void
    {
        $this->supervisorctl(['start', $this->group($worker)], $worker, 'worker_start');
    }

    public function stop(Worker $worker): void
    {
        $this->supervisorctl(['stop', $this->group($worker)], $worker, 'worker_stop');
    }

    /**
     * Restart, in the way this kind of worker is meant to be restarted.
     *
     * A Laravel queue worker is told to finish its current job and exit
     * (`queue:restart`); supervisor then starts it again with the new code,
     * because `autorestart` treats a clean exit as something to replace. That
     * is gentler than restarting the program, which can kill a job mid-flight.
     * Horizon has its own equivalent. Anything else has no such protocol, so
     * the program is restarted directly.
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

        $this->supervisorctl(['restart', $this->group($worker)], $worker, 'worker_restart');
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
        // Exit 3 is supervisorctl's "some processes are not running", which is
        // the answer this method exists to report rather than a failure to ask
        // — treating it as an error would make every stopped worker an entry
        // on the admin error dashboard.
        $result = $this->serverOps->run(
            ['supervisorctl', 'status', $this->group($worker)],
            $this->context($worker, 'worker_status'),
            timeout: 30,
            expectedExitCodes: [3],
        );

        $running = 0;

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            // `sv-worker-shop-queue:sv-worker-shop-queue_00   RUNNING   pid 123, uptime 0:04:11`
            // Matched on the word rather than split by whitespace: the state
            // is the second column, and a program name containing a space
            // could not exist, but a missing line could — and `RUNNING` is
            // unambiguous either way.
            if (preg_match('/^\S+\s+RUNNING\b/', trim($line)) === 1) {
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
        // supervisor restart its program directly instead of logging an
        // expected missing-command failure before doing that anyway.
        if ($this->frameworks->detect($application) === FrameworkDetector::CRAFT) {
            return null;
        }

        return match ($worker->kind) {
            Worker::KIND_QUEUE => [$php, $root.'/artisan', 'queue:restart'],
            Worker::KIND_HORIZON => [$php, $root.'/artisan', 'horizon:terminate'],
        };
    }

    public function render(Worker $worker): string
    {
        $application = $worker->application;
        $projectRoot = $this->frameworks->root($application);
        $directory = $worker->directory ?: $projectRoot;

        return View::make('server.programs.worker', [
            'worker' => $worker,
            'program' => $this->program($worker),
            'command' => $this->command($worker),
            'directory' => $directory,
            'processes' => $worker->processes,
            // The site's own account unless an adopted block named another.
            // Rewriting someone else's choice silently would change who owns
            // the files a running job writes.
            'user' => $worker->user ?: $application->systemUser->username,
            'autoStart' => $worker->auto_start,
            'autoRestart' => $worker->auto_restart,
            'stopWaitSeconds' => $worker->stop_wait_seconds,
            'logFile' => $worker->log_file ?: $application->logsPath().'/'.$this->program($worker).'.log',
            'logLevel' => $worker->log_level,
            'extraConfig' => $worker->extra_config,
            'path' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ])->render();
    }

    private function directory(Worker $worker): string
    {
        return $worker->directory ?: $this->frameworks->root($worker->application);
    }

    /**
     * The command, with a bare `php` resolved to a path.
     *
     * supervisor does run its command through a shell-like split rather than
     * exec'ing directly, so a bare `php` would in fact be found via PATH — but
     * it would be *whichever* php is first on it, and a site pinned to 8.3
     * whose worker silently ran on 8.4 is the kind of difference that only
     * shows up as a serialisation error weeks later. Resolved for the same
     * reason the systemd unit had to.
     */
    private function command(Worker $worker): string
    {
        $parts = preg_split('/\s+/', trim($worker->command)) ?: [];
        $binary = array_shift($parts) ?? '';

        if (! str_starts_with($binary, '/') && str_starts_with($binary, 'php')) {
            $binary = '/usr/bin/'.$binary;
        }

        return trim($binary.' '.implode(' ', $parts));
    }

    /**
     * Re-read the configs and apply the difference.
     *
     * Not fatal on its own: a `reread` that fails leaves the file on disk and
     * the previous program running, which is a state someone can act on. The
     * caller checks whether the worker actually came up.
     */
    private function reload(Worker $worker): void
    {
        $this->supervisorctl(['reread'], $worker, 'worker_reread');
        $this->supervisorctl(['update'], $worker, 'worker_update');
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function supervisorctl(array $arguments, Worker $worker, string $op): ServerOpsResult
    {
        return $this->serverOps->run(
            ['supervisorctl', ...$arguments],
            $this->context($worker, $op),
            timeout: 120,
        );
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
}
