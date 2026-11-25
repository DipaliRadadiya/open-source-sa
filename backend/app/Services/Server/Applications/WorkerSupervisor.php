<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\Application\SupervisorMissingException;
use App\Exceptions\Server\Application\SystemUserMissingException;
use App\Exceptions\Server\Application\UnprovenWorkerProgramException;
use App\Exceptions\Server\Application\WorkerControlException;
use App\Exceptions\Server\ServerOperationException;
use App\Models\SystemUser;
use App\Models\Worker;
use App\Services\Server\ManagedFile;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\Sync\Discoverers\WorkerDiscoverer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs an application's background workers, as supervisord programs.
 *
 * A sibling of ProcessSupervisor, not an extension: that one supervises *the* application process
 * (exactly one, its command on the application row, on systemd); a worker is one of many, has its
 * own row, and can be asked for in multiples.
 *
 * supervisord rather than systemd: it is what the commercial panel and every Laravel queue tutorial
 * use, so a migrated server's workers are already in that format. `WorkerDiscoverer` reads
 * `/etc/supervisor/conf.d` to adopt them, and writing the same format keeps adopt-then-manage to one
 * format and lets an adopted worker be edited. supervisor also owns the copies (`numprocs`): the panel
 * stores only the number it asked for, and "3 of 4 running" is answered by supervisord.
 */
class WorkerSupervisor
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private FrameworkDetector $frameworks,
        private PhpRuntime $php,
    ) {}

    /** `sv-worker-shop-queue` — the program name. */
    public function program(Worker $worker): string
    {
        // The slug, not the id (readable on the box) and not the name (renaming relabels without
        // restarting a running process).
        return $worker->hasProgram() ? (string) $worker->program : 'sv-worker-'.$worker->slug;
    }

    /**
     * `sv-worker-shop-queue:*` — every process of the program. supervisorctl treats a `numprocs`
     * program as a group and the bare name addresses nothing ("no such process"), so the `:*` is
     * needed.
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
     * Is supervisord actually on this box?
     *
     * Not a given: panels installed before workers moved to supervisord have no supervisor and no
     * `conf.d`, and the updater never runs apt. Writing into the missing directory surfaced as a buried
     * `tee: ... No such file or directory`; say so in plain words instead (see
     * {@see Fail2banManager::installed()}).
     *
     * A refused probe is not "no": sudo exits non-zero before running `which` when the grant does not
     * cover it (see {@see ServerOpsResult::$answered}). Read as "no", the create endpoint would send a
     * server with a stale grant to apt for a supervisor it already has. Raised here, once, for both callers.
     *
     * @throws WorkerControlException when sudo refuses the probe
     */
    public function installed(): bool
    {
        $probe = $this->serverOps->run(
            ['which', 'supervisorctl'],
            ['feature' => 'application', 'op' => 'worker_detect'],
        );

        if ($probe->denied) {
            throw new WorkerControlException($probe->reference, denied: true);
        }

        return $probe->ok;
    }

    /**
     * Refuse early if this server cannot run workers at all. Called before the row is created as well
     * as before the program is written: a worker listed but never started is found when the queue is
     * already hours behind.
     *
     * @throws SupervisorMissingException
     */
    public function assertAvailable(): void
    {
        if (! $this->installed()) {
            throw new SupervisorMissingException;
        }
    }

    /**
     * Write the program and bring the requested number of copies up.
     *
     * Verified with `status` afterwards: starting succeeds for a program that starts and dies at once
     * (a mistyped command), and reporting that as running would be worse than refusing it.
     *
     * @throws ProvisioningFailedException
     */
    public function apply(Worker $worker): void
    {
        // Before the write, not after it fails: a missing package is something the person can act on,
        // `tee: No such file or directory` is not. Covers every caller.
        $this->assertAvailable();

        $context = $this->context($worker, 'worker_write');

        $written = $this->files->put($this->configPath($worker), $this->render($worker), $context);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_unit', $written->reference);
        }

        // `reread` parses the files, `update` applies the difference (adds the program, retires copies
        // when `numprocs` dropped). Both, in that order: `update` alone acts on a config not yet re-read.
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

    /**
     * Opt into v7 naming only on an explicit create/save, never during a
     * deploy, control action or migration. Null remains the legacy identity.
     *
     * The old file must match the pre-edit rendering byte for byte before we
     * retire it. A foreign or manually replaced config is not ours to delete.
     */
    public function applySaved(Worker $worker, ?Worker $before = null): void
    {
        if ($worker->hasProgram()) {
            $this->apply($worker);

            return;
        }

        $this->withProgramLock(function () use ($worker, $before): void {
            $this->assertAvailable();
            $directory = rtrim((string) config('server.applications.supervisor_dir', '/etc/supervisor/conf.d'), '/');
            // Every entry, symlinks and directories too: neither is a free
            // target. A refused/unreadable listing is never an empty folder.
            // `find`, not `ls`: it is already on the privilege allowlist.
            $listing = $this->serverOps->run(['find', $directory, '-mindepth', '1', '-maxdepth', '1', '-printf', '%f\n'], $this->context($worker, 'worker_program_names'));
            if ($listing->failed()) {
                throw new WorkerControlException($listing->reference, denied: $listing->denied);
            }
            $files = preg_split('/\R/', trim($listing->output())) ?: [];
            $reserved = Worker::query()->get()->map(fn (Worker $item) => $this->program($item))->all();
            // Loaded groups can outlive their files. Do not take over one
            // simply because its original config is no longer on disk.
            $loaded = $this->supervisorctl(['status'], $worker, 'worker_loaded_programs');
            if (! in_array($loaded->exitCode(), [0, 3], true)) {
                throw new WorkerControlException($loaded->reference);
            }
            foreach (preg_split('/\R/', trim($loaded->output())) ?: [] as $line) {
                if (preg_match('/^(\S+)\s+(RUNNING|STOPPED|STARTING|BACKOFF|EXITED|FATAL|UNKNOWN|STOPPING)\b/', $line, $match)) {
                    $reserved[] = explode(':', $match[1])[0];
                } elseif ($line !== '') {
                    throw new WorkerControlException($loaded->reference);
                }
            }
            foreach ($files as $file) {
                if (! str_ends_with($file, '.conf')) {
                    continue;
                }
                $definition = $this->files->get($directory.'/'.$file, $this->context($worker, 'worker_program_read'));
                if ($definition->failed()) {
                    throw new WorkerControlException($definition->reference, denied: $definition->denied);
                }
                preg_match_all('/^[ \t]*\[program:([^\]]+)\]/m', $definition->output(), $headers);
                $reserved = array_merge($reserved, $headers[1]);
            }
            $oldPath = $this->configPath($worker);
            $old = null;
            if ($before !== null && ! in_array(basename($oldPath), $files, true)) {
                // A loaded process may outlive a missing file. Do not start a
                // second group when ownership/state cannot be verified.
                throw new WorkerControlException($listing->reference);
            }
            if ($before !== null && in_array(basename($oldPath), $files, true)) {
                $old = $this->files->get($oldPath, $this->context($worker, 'worker_legacy_read'));
                if ($old->failed() || $old->output() !== $this->render($before)) {
                    throw new WorkerControlException($old->reference, denied: $old->denied);
                }
            }

            $slug = Str::slug($worker->name);
            $base = substr($slug === '' ? 'worker' : $slug, 0, 64);
            $program = $base;
            $suffix = 2;
            while (in_array($program.'.conf', $files, true)
                || in_array($program, $reserved, true)) {
                if ($suffix > 10000) {
                    throw new WorkerControlException($listing->reference);
                }
                $tail = '-'.$suffix++;
                $program = substr($base, 0, 64 - strlen($tail)).$tail;
            }

            // Retire and reload BEFORE writing/starting the new name. There
            // can never be two configured groups consuming the same queue.
            $allocated = false;
            $retired = false;
            $oldWasRunning = false;
            try {
                if ($old !== null) {
                    $state = $this->supervisorctl(['status', $this->group($before)], $before, 'worker_legacy_status');
                    // Exit 3 means stopped, not an unreadable/unknown group.
                    if (! in_array($state->exitCode(), [0, 3], true)
                        || ! str_contains($state->output(), $this->program($before).':')) {
                        throw new WorkerControlException($state->reference);
                    }
                    $oldWasRunning = str_contains($state->output(), 'RUNNING');
                    if ($oldWasRunning) {
                        $stopped = $this->supervisorctl(['stop', $this->group($before)], $before, 'worker_legacy_stop');
                        if ($stopped->failed()) {
                            throw new WorkerControlException($stopped->reference);
                        }
                    }
                    $retired = true;
                    $removed = $this->files->delete($oldPath, $this->context($worker, 'worker_legacy_remove'));
                    if ($removed->failed()) {
                        throw new WorkerControlException($removed->reference);
                    }
                    $this->checkedReload($worker);
                }

                $worker->forceFill(['program' => $program])->save();
                $allocated = true;
                $this->apply($worker);
                $this->checkedReload($worker);
            } catch (Throwable $e) {
                if ($e instanceof ServerOperationException && $e->denied) {
                    throw $e;
                }
                // Do not revive the old group until the new one is known
                // stopped and removed. If cleanup fails, keep its identity
                // tracked and surface that failure instead of doubling it.
                if ($allocated) {
                    $neverLoaded = $e instanceof ProvisioningFailedException && $e->step === 'write_unit';
                    $state = $neverLoaded ? null : $this->supervisorctl(['status', $this->group($worker)], $worker, 'worker_new_cleanup_status');
                    if ($state !== null && str_contains($state->output(), 'RUNNING')) {
                        $stopped = $this->supervisorctl(['stop', $this->group($worker)], $worker, 'worker_new_cleanup_stop');
                        if ($stopped->failed()) {
                            throw new WorkerControlException($stopped->reference);
                        }
                    } elseif ($state !== null && ! ($state->exitCode() === 3 && str_contains($state->output(), 'STOPPED'))
                        && ! $this->notLoaded($state)) {
                        throw new WorkerControlException($state->reference);
                    }
                    $removed = $this->files->delete($this->configPath($worker), $this->context($worker, 'worker_new_cleanup_remove'));
                    if ($removed->failed()) {
                        throw new WorkerControlException($removed->reference);
                    }
                    $this->checkedReload($worker, $state !== null && $state->exitCode() !== 4);
                }
                $worker->forceFill(['program' => null])->save();
                if ($retired && $old !== null) {
                    $restored = $this->files->put($oldPath, $old->output(), $this->context($worker, 'worker_legacy_restore'));
                    if ($restored->failed()) {
                        throw new WorkerControlException($restored->reference);
                    }
                    $this->checkedReload($worker);
                    // update may autostart a restored group. Preserve the
                    // observed pre-save state rather than blindly issuing
                    // start (which fails when it is already running).
                    $state = $this->supervisorctl(['status', $this->group($before)], $before, 'worker_legacy_restored_status');
                    if (! in_array($state->exitCode(), [0, 3], true)
                        || ! str_contains($state->output(), $this->program($before).':')) {
                        throw new WorkerControlException($state->reference);
                    }
                    $isRunning = str_contains($state->output(), 'RUNNING');
                    if ($oldWasRunning !== $isRunning) {
                        $action = $oldWasRunning ? 'start' : 'stop';
                        $controlled = $this->supervisorctl([$action, $this->group($before)], $before, 'worker_legacy_restore_'.$action);
                        if ($controlled->failed()) {
                            throw new WorkerControlException($controlled->reference);
                        }
                    }
                }
                throw $e;
            }
        });
    }

    /**
     * supervisord has nothing loaded under this name, so there is nothing to stop. `status name:*`
     * answers "no such group" when the program was never loaded (a new worker that died before its
     * first reload) and "no such process" for a group without that member; both exit 4. Accepting only
     * the second left a new worker's row behind and made it undeletable.
     */
    private function notLoaded(ServerOpsResult $state): bool
    {
        return $state->exitCode() === 4
            && (str_contains($state->output(), 'no such process') || str_contains($state->output(), 'no such group'));
    }

    /** A host-local mutex without an expiring lease while commands run. */
    private function withProgramLock(callable $operation): void
    {
        $handle = fopen(storage_path('framework/cache/worker-program.lock'), 'c');
        if ($handle === false) {
            throw new WorkerControlException((string) Str::uuid());
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new WorkerControlException((string) Str::uuid());
            }
            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function checkedReload(Worker $worker, bool $update = true): void
    {
        foreach ($update ? ['reread', 'update'] : ['reread'] as $verb) {
            $arguments = $verb === 'update' ? [$verb, $this->program($worker)] : [$verb];
            $result = $this->supervisorctl($arguments, $worker, 'worker_program_'.$verb);
            if ($result->failed()) {
                throw new WorkerControlException($result->reference);
            }
        }
    }

    /**
     * Rewrite a switched-off worker's program when it is out of date, so it stays off after the next
     * reboot (older files were written with autostart=true). A running worker is never touched. True
     * when it rewrote something.
     */
    public function refreshDisabled(Worker $worker): bool
    {
        if ($worker->hasProgram() || $worker->enabled || ! $this->installed()) {
            return false;
        }

        $context = $this->context($worker, 'worker_refresh');
        $current = $this->files->get($this->configPath($worker), $context);
        $rendered = $this->render($worker);

        // Not there, or unreadable: nothing of ours to correct.
        if ($current->failed() || $current->output() === $rendered) {
            return false;
        }

        if ($this->files->put($this->configPath($worker), $rendered, $context)->failed()) {
            return false;
        }

        $this->reload($worker);

        return true;
    }

    /**
     * Why an adopted worker's own program file no longer proves that it is this worker's alone, or
     * null when it still does. Read-only.
     *
     * Null straight away for the panel's own workers (`sv-worker-{slug}`): only the panel writes that
     * name. `program` is set only by Server Sync, for a file holding exactly one program named after
     * the file, inside this site ({@see WorkerDiscoverer::adopt()}). This checks that still holds
     * before the file is deleted, because a file since edited into a shared one would take somebody
     * else's programs with it. A file that is gone proves nothing is left to delete; the program is
     * still stopped by its recorded name.
     */
    public function unprovableProgram(Worker $worker): ?string
    {
        if (! $worker->hasProgram()) {
            return null;
        }

        $program = (string) $worker->program;
        $directory = rtrim((string) config('server.applications.supervisor_dir', '/etc/supervisor/conf.d'), '/');

        // The rule adopt() recorded it under. A name it could not have
        // recorded is not one to build a path from.
        if ($directory === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $program) !== 1) {
            return 'unexpected_path';
        }

        $context = $this->context($worker, 'worker_verify_adopted');

        // `-P` and a listing of the directory, not the file: a symlink is reported, not followed, and
        // "not there" is an empty answer rather than an error a refused grant would look like.
        $listing = $this->serverOps->run(
            ['find', '-P', $directory, '-mindepth', '1', '-maxdepth', '1', '-name', $program.'.conf', '-printf', '%y\n'],
            $context,
        );

        if ($listing->failed()) {
            return 'unreadable';
        }

        $type = trim($listing->output());

        if ($type === '') {
            return null;
        }

        if ($type !== 'f') {
            return 'not_regular';
        }

        $read = $this->files->get($this->configPath($worker), $context);

        if ($read->failed()) {
            return 'unreadable';
        }

        preg_match_all('/^[ \t]*\[program:([^\]]+)\]/mi', $read->output(), $blocks);

        if (count($blocks[1]) !== 1 || trim($blocks[1][0]) !== $program) {
            return 'worker_shared';
        }

        try {
            $root = rtrim((string) $worker->application?->rootPath(), '/');
        } catch (SystemUserMissingException) {
            return 'not_this_site';
        }

        // Where the discoverer looked when it attributed the program: the
        // working directory, otherwise the command.
        $directoryValue = $this->programSetting($read->output(), 'directory');
        $command = $this->programSetting($read->output(), 'command') ?? '';

        if ($root === '') {
            return 'not_this_site';
        }

        if ($directoryValue !== null) {
            $inside = ! str_contains($directoryValue.'/', '/../')
                && (rtrim($directoryValue, '/') === $root || str_starts_with($directoryValue, $root.'/'));
        } else {
            // A path token, not a substring: `/home/u/shop` must not claim
            // `/home/u/shopping`, and `/home/u/shop/../x` is somewhere else.
            $inside = ! str_contains($command, '/../')
                && preg_match('#(^|[\s=\'"])'.preg_quote($root, '#').'(/|[\s\'"]|$)#', $command) === 1;
        }

        return $inside ? null : 'not_this_site';
    }

    /** Stop every copy, then delete the program and let supervisord forget it. */
    public function remove(Worker $worker): void
    {
        // An adopted program whose file may now hold somebody else's programs
        // is neither stopped nor deleted. Refused before anything runs.
        $unprovable = $this->unprovableProgram($worker);

        if ($unprovable !== null) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->warning('adopted worker program left in place: it no longer provably belongs to this worker', $this->context($worker, 'worker_remove') + [
                'reference' => $reference,
                'reason' => $unprovable,
                'path' => $this->configPath($worker),
            ]);

            throw new UnprovenWorkerProgramException($reference, $unprovable, $this->configPath($worker));
        }

        $loaded = true;
        $stopped = $this->supervisorctl(['stop', $this->group($worker)], $worker, 'worker_stop');
        if ($stopped->failed()) {
            $state = $this->supervisorctl(['status', $this->group($worker)], $worker, 'worker_remove_status');
            if ($this->notLoaded($state)) {
                $loaded = false;
            } elseif ($state->exitCode() === 3 && trim($state->output()) !== '') {
                // Already stopped/fatal is safe, but BACKOFF/STARTING/EXITED
                // may still restart. Every reported member must be inactive.
                foreach (preg_split('/\R/', trim($state->output())) ?: [] as $line) {
                    if (! preg_match('/^'.preg_quote($this->program($worker), '/').':\S+\s+(STOPPED|FATAL)\b/', $line)) {
                        throw new WorkerControlException($stopped->reference);
                    }
                }
            } else {
                throw new WorkerControlException($stopped->reference);
            }
        }

        $removed = $this->files->delete($this->configPath($worker), $this->context($worker, 'worker_remove'));
        if ($removed->failed()) {
            throw new WorkerControlException($removed->reference, denied: $removed->denied);
        }
        // A failed stop/delete/reload must not let callers discard the row
        // which owns a possibly still-configured program. Already absent is
        // idempotent: reread the deletion but do not update a nonexistent group.
        $this->checkedReload($worker, $loaded);
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
     * A Laravel queue worker is told to finish its current job and exit (`queue:restart`) and supervisor
     * starts it again with the new code, which is gentler than restarting the program (that can kill a
     * job mid-flight). Horizon has its own equivalent; anything else is restarted directly.
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
     * How many copies are actually up, right now. Reported as "running of requested", not a boolean:
     * three of four processes alive is a real state a green dot would hide.
     *
     * @return array{running: int, requested: int, state: string}
     */
    public function status(Worker $worker): array
    {
        // Exit 3 is supervisorctl's "some processes are not running": the answer this method reports, not
        // a failure (an error would put every stopped worker on the admin error dashboard).
        $result = $this->serverOps->run(
            ['supervisorctl', 'status', $this->group($worker)],
            $this->context($worker, 'worker_status'),
            timeout: 30,
            expectedExitCodes: [3],
        );

        $running = 0;

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            // `sv-worker-shop-queue:sv-worker-shop-queue_00   RUNNING   pid 123, uptime 0:04:11`
            // Matched on the word, not split by whitespace: `RUNNING` is unambiguous either way.
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

        // Resolved, not bare: this runs directly rather than through supervisor, and no stack provides a
        // versioned `php8.4` on PATH on OpenLiteSpeed (only `php`).
        $php = $this->phpBinary('php'.($application->php_version ?: ''), $worker);

        if (! in_array($worker->kind, [Worker::KIND_QUEUE, Worker::KIND_HORIZON], true)) {
            return null;
        }

        // Craft has a queue, but not Laravel's `artisan queue:restart`. Let
        // supervisor restart its program directly instead of logging an
        // expected missing-command failure before doing that anyway.
        if ($this->frameworks->detect($application) === FrameworkDetector::CRAFT) {
            return null;
        }

        // As the account the worker's processes run as (the one render() names). As the panel's account
        // it can read a site's code but not write `storage/`, so Laravel exited 1 and every "graceful"
        // restart fell back to `supervisorctl restart`, killing the running job. As root it would leave
        // root-owned cache files the site cannot write.
        /** @var SystemUser $owner */
        $owner = $application->systemUser;
        $user = $worker->user ?: $owner->username;

        return match ($worker->kind) {
            Worker::KIND_QUEUE => ['runuser', '-u', $user, '--', $php, $root.'/artisan', 'queue:restart'],
            Worker::KIND_HORIZON => ['runuser', '-u', $user, '--', $php, $root.'/artisan', 'horizon:terminate'],
        };
    }

    public function render(Worker $worker): string
    {
        $application = $worker->application;
        $projectRoot = $this->frameworks->root($application);
        $directory = $worker->directory ?: $projectRoot;
        /** @var SystemUser $owner */
        $owner = $application->systemUser;

        return View::make('server.programs.worker', [
            'worker' => $worker,
            'program' => $this->program($worker),
            'command' => $this->command($worker),
            'directory' => $directory,
            'processes' => $worker->processes,
            // The site's own account unless an adopted block named another; rewriting that silently
            // would change who owns the files a running job writes.
            'user' => $worker->user ?: $owner->username,
            // Off while the worker is disabled too: apply() stops it, but autostart=true would bring it
            // back whenever supervisord next starts (every reboot).
            'autoStart' => $worker->auto_start && $worker->enabled,
            'autoRestart' => $worker->auto_restart,
            'stopWaitSeconds' => $worker->stop_wait_seconds,
            'logFile' => $this->logFile($worker),
            'logLevel' => $worker->log_level,
            'extraConfig' => $worker->extra_config,
            'path' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ])->render();
    }

    /**
     * The worker's last words, for a start that failed.
     *
     * stderr goes to the same file (`redirect_stderr`), so a command that dies at once leaves its
     * reason here (`php -v` printing and exiting, a missing file). Null when there is nothing to quote.
     */
    public function lastOutput(Worker $worker): ?string
    {
        $tail = $this->serverOps->run(
            ['tail', '-n', '20', $this->logFile($worker)],
            $this->context($worker, 'worker_last_output'),
            // Exit 1: no such log yet — the program never wrote one.
            expectedExitCodes: [1],
        );

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $tail->output()) ?: [])));

        return $lines === [] ? null : mb_strimwidth((string) end($lines), 0, 300, '…');
    }

    /**
     * The file this worker's output lands in. Public: the Logs screen and the template must agree, so
     * one expression serves both.
     */
    public function logFile(Worker $worker): string
    {
        return $worker->log_file
            ?: $worker->application->logsPath().'/'.($worker->hasProgram() ? 'supervisor-'.$worker->program : $this->program($worker)).'.log';
    }

    private function directory(Worker $worker): string
    {
        return $worker->directory ?: $this->frameworks->root($worker->application);
    }

    /**
     * The command, with a bare `php` resolved to a path.
     *
     * A bare `php` is found via PATH, but as *whichever* php is first on it: a site pinned to 8.3 whose
     * worker silently ran on 8.4 shows up as a serialisation error weeks later.
     *
     * 🔴 Resolved by the stack, not by writing `/usr/bin/`: that is only true on the FPM stacks. An
     * OpenLiteSpeed box has no ondrej PHP (it lives at `/usr/local/lsws/lsphpXX/bin/php`), so the program
     * pointed at a file that does not exist. `PhpStack::binaryPath()` knows the answer for both stacks.
     */
    private function command(Worker $worker): string
    {
        $parts = preg_split('/\s+/', trim($worker->command)) ?: [];
        $binary = array_shift($parts) ?? '';

        if (! str_starts_with($binary, '/') && str_starts_with($binary, 'php')) {
            $binary = $this->phpBinary($binary, $worker);
        }

        return trim($binary.' '.implode(' ', $parts));
    }

    /**
     * A typed `php` / `php8.2` resolved to this stack's real binary.
     *
     * A typed version wins (`php8.2` on an 8.4 site meant 8.2); a bare `php` uses the site's version.
     *
     * Never fatal: anything unresolvable (a non-`X.Y` version, an empty answer, the bare `php` from a
     * blank `SERVER_PHP_BINARY_PATTERN`) leaves the name as typed and lets PATH decide, so a failure to
     * resolve cannot turn a worker that runs into one that does not.
     */
    private function phpBinary(string $typed, Worker $worker): string
    {
        $version = substr($typed, 3);

        if ($version === '') {
            $version = (string) $worker->application?->php_version;
        }

        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return $typed;
        }

        $resolved = $this->php->binaryPath($version);

        return ($resolved === '' || $resolved === 'php') ? $typed : $resolved;
    }

    /**
     * Re-read the configs and apply the difference. Not fatal on its own: a failed `reread` leaves the
     * file on disk and the previous program running; the caller checks whether the worker came up.
     */
    private function reload(Worker $worker): void
    {
        $this->supervisorctl(['reread'], $worker, 'worker_reread');
        $this->supervisorctl(['update', $this->program($worker)], $worker, 'worker_update');
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function supervisorctl(array $arguments, Worker $worker, string $op): ServerOpsResult
    {
        $result = $this->serverOps->run(
            ['supervisorctl', ...$arguments],
            $this->context($worker, $op),
            timeout: 120,
        );

        // Every supervisorctl call, not just the fatal ones. reload() tolerates supervisord disagreeing
        // with a config, but a refused grant is not a state to carry on from: nothing ran, and continuing
        // only moves the report to a later command.
        if ($result->denied) {
            throw new WorkerControlException($result->reference, denied: true);
        }

        return $result;
    }

    /** `key=value` in a program block, first occurrence, quotes stripped — as the discoverer reads it. */
    private function programSetting(string $contents, string $key): ?string
    {
        if (! preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+?)\s*$/mi', $contents, $matches)) {
            return null;
        }

        return trim($matches[1], " \t\"'") ?: null;
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
