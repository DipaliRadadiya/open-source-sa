<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\Application\WorkerControlException;
use App\Models\Application;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Models\Worker;
use App\Services\Server\Applications\WorkerSupervisor;
use App\Services\Server\Sync\Discoverers\WorkerDiscoverer;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

class V7WorkerHost
{
    public static array $files = [];

    public static array $running = [];

    public static array $commands = [];

    public static ?string $fail = null;

    public static function fake(): void
    {
        self::$files = [];
        self::$running = [];
        self::$commands = [];
        self::$fail = null;
        Process::fake(function ($process) {
            $args = (array) $process->command;
            self::$commands[] = implode(' ', $args);
            if (self::$fail === implode(' ', $args)) {
                self::$fail = null;

                return Process::result(errorOutput: 'simulated failure', exitCode: 1);
            }
            // The adopted-program re-check before a remove lists one exact
            // name without following links (FS-C22 #14).
            if ($args[0] === 'find' && in_array('-name', $args, true)) {
                $name = $args[array_search('-name', $args, true) + 1];

                return Process::result(output: isset(self::$files[$args[2].'/'.$name]) ? "f\n" : '');
            }
            if ($args[0] === 'find') {
                return Process::result(output: implode("\n", array_map('basename', array_keys(self::$files))));
            }
            if ($args[0] === 'cat') {
                return isset(self::$files[$args[1]])
                    ? Process::result(output: self::$files[$args[1]])
                    : Process::result(exitCode: 1);
            }
            if ($args[0] === 'tee') {
                self::$files[$args[1]] = (string) $process->input;
            }
            if ($args[0] === 'rm') {
                unset(self::$files[end($args)]);
            }
            if ($args[0] === 'test') {
                return Process::result(exitCode: 1);
            }
            if ($args[0] === 'supervisorctl') {
                $program = explode(':', $args[2] ?? '')[0];
                if ($args[1] === 'status') {
                    if (! isset($args[2])) {
                        $lines = [];
                        foreach (self::$running as $name => $active) {
                            $lines[] = $name.':'.$name.'_00 '.($active ? 'RUNNING pid 123' : 'STOPPED');
                        }

                        return Process::result(output: implode("\n", $lines), exitCode: in_array(false, self::$running, true) ? 3 : 0);
                    }
                    if (! array_key_exists($program, self::$running)) {
                        return Process::result(output: $program.': ERROR (no such process)', exitCode: 4);
                    }
                    $running = self::$running[$program];

                    return Process::result(output: $program.':'.$program.'_00 '.($running ? 'RUNNING pid 123, uptime 0:01:00' : 'STOPPED'), exitCode: $running ? 0 : 3);
                }
                if (in_array($args[1], ['start', 'restart'], true)) {
                    self::$running[$program] = true;
                }
                if ($args[1] === 'stop') {
                    if (! array_key_exists($program, self::$running)) {
                        return Process::result(output: $program.': ERROR (no such process)', exitCode: 2);
                    }
                    if (! self::$running[$program]) {
                        return Process::result(output: $program.': ERROR (not running)', exitCode: 2);
                    }
                    self::$running[$program] = false;
                }
                if ($args[1] === 'update') {
                    $path = '/isolated-supervisor/'.$program.'.conf';
                    if (isset(self::$files[$path]) && ! array_key_exists($program, self::$running)) {
                        self::$running[$program] = str_contains(self::$files[$path], 'autostart=true');
                    }
                    foreach (array_keys(self::$running) as $name) {
                        if (isset($args[2]) && $name !== $args[2]) {
                            continue;
                        }
                        if (! isset(self::$files['/isolated-supervisor/'.$name.'.conf'])) {
                            unset(self::$running[$name]);
                        }
                    }
                }
            }

            return Process::result();
        });
    }
}

beforeEach(function () {
    config()->set('server.applications.supervisor_dir', '/isolated-supervisor');
    $owner = SystemUser::create(['username' => 'v7worker', 'home_path' => '/home/v7worker']);
    $this->workerApplication = Application::forceCreate([
        'system_user_id' => $owner->id, 'name' => 'Shop', 'slug' => 'shop',
        'domain' => 'shop.test', 'site_type' => 'custom', 'serving_profile' => 'php',
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);
    V7WorkerHost::fake();
});

function v7LayoutWorker(Application $application, string $name = 'Queue'): Worker
{
    return Worker::create([
        'application_id' => $application->id, 'name' => $name,
        'command' => '/bin/sleep 600', 'kind' => 'custom', 'enabled' => true,
    ])->refresh()->load('application.systemUser');
}

function v7LayoutLegacy(Worker $worker, bool $running = true): string
{
    $supervisor = app(WorkerSupervisor::class);
    $path = $supervisor->configPath($worker);
    V7WorkerHost::$files[$path] = $supervisor->render($worker);
    V7WorkerHost::$running[$supervisor->program($worker)] = $running;

    return $path;
}

it('leaves the old program and log unchanged when the nullable column is introduced', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $migration = require database_path('migrations/2026_10_10_103000_add_program_to_workers_table.php');
    $migration->down();
    expect(Schema::hasColumn('workers', 'program'))->toBeFalse();
    $migration->up();
    $worker->refresh();
    $supervisor = app(WorkerSupervisor::class);
    expect($worker->program)->toBeNull()
        ->and($supervisor->program($worker))->toBe('sv-worker-shop-queue')
        ->and($supervisor->logFile($worker))->toEndWith('/logs/sv-worker-shop-queue.log')
        ->and(V7WorkerHost::$commands)->toBe([]);
});

it('uses the v7 filename program and log for a newly saved worker', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $supervisor = app(WorkerSupervisor::class);
    $supervisor->applySaved($worker);
    expect($worker->fresh()->program)->toBe('queue')
        ->and($supervisor->configPath($worker))->toBe('/isolated-supervisor/queue.conf')
        ->and($supervisor->logFile($worker))->toEndWith('/logs/supervisor-queue.log')
        ->and(V7WorkerHost::$files['/isolated-supervisor/queue.conf'])->toContain('[program:queue]');
});

it('checks foreign filenames and program names in differently named files', function (string $header) {
    V7WorkerHost::$files['/isolated-supervisor/queue.conf'] = 'foreign file';
    V7WorkerHost::$files['/isolated-supervisor/shared.conf'] = $header."\ncommand=/bin/sleep 1\n";
    $foreign = V7WorkerHost::$files;
    $worker = v7LayoutWorker($this->workerApplication);
    app(WorkerSupervisor::class)->applySaved($worker);
    expect($worker->program)->toBe('queue-3');
    foreach ($foreign as $path => $content) {
        expect(V7WorkerHost::$files[$path])->toBe($content);
    }
})->with(['[program:queue-2]', " \t[program:queue-2]"]);

it('allocates globally unique bounded programs across applications', function () {
    $first = v7LayoutWorker($this->workerApplication, str_repeat('q', 255));
    app(WorkerSupervisor::class)->applySaved($first);
    $other = $this->workerApplication->replicate();
    $other->forceFill(['name' => 'Other', 'slug' => 'other', 'domain' => 'other.test'])->save();
    $second = v7LayoutWorker($other, str_repeat('q', 255));
    app(WorkerSupervisor::class)->applySaved($second);
    expect(strlen($first->program))->toBe(64)
        ->and(strlen($second->program))->toBe(64)
        ->and($second->program)->toEndWith('-2')
        ->and($second->program)->not->toBe($first->program);
});

it('retires the owned old program before starting the new program on save', function (bool $running) {
    $worker = v7LayoutWorker($this->workerApplication);
    $oldPath = v7LayoutLegacy($worker, $running);
    $before = clone $worker;
    $worker->update(['name' => 'Emails']);
    app(WorkerSupervisor::class)->applySaved($worker, $before);
    expect($worker->program)->toBe('emails')
        ->and(V7WorkerHost::$files)->not->toHaveKey($oldPath)
        ->and(V7WorkerHost::$running)->not->toHaveKey('sv-worker-shop-queue')
        ->and(V7WorkerHost::$running['emails'])->toBeTrue();
    $remove = array_search('rm -f '.$oldPath, V7WorkerHost::$commands, true);
    $start = array_search('supervisorctl restart emails:*', V7WorkerHost::$commands, true);
    expect($remove)->toBeLessThan($start);
})->with([true, false]);

it('refuses foreign or missing legacy definitions without deleting or starting anything', function (bool $missing) {
    $worker = v7LayoutWorker($this->workerApplication);
    $oldPath = v7LayoutLegacy($worker);
    if ($missing) {
        unset(V7WorkerHost::$files[$oldPath]);
    } else {
        V7WorkerHost::$files[$oldPath] = '[program:foreign]';
    }
    $snapshot = V7WorkerHost::$files;
    expect(fn () => app(WorkerSupervisor::class)->applySaved($worker, clone $worker))->toThrow(WorkerControlException::class);
    expect(V7WorkerHost::$files)->toBe($snapshot)
        ->and($worker->fresh()->program)->toBeNull()
        ->and(V7WorkerHost::$running['sv-worker-shop-queue'])->toBeTrue();
})->with([true, false]);

it('refuses an unreadable directory before writing a config', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    V7WorkerHost::$fail = 'find /isolated-supervisor -mindepth 1 -maxdepth 1 -printf %f\n';
    expect(fn () => app(WorkerSupervisor::class)->applySaved($worker))->toThrow(WorkerControlException::class);
    expect(V7WorkerHost::$files)->toBe([])->and($worker->fresh()->program)->toBeNull();
});

it('restores the old identity and config after a retirement reload failure', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $oldPath = v7LayoutLegacy($worker);
    $original = V7WorkerHost::$files[$oldPath];
    V7WorkerHost::$fail = 'supervisorctl update sv-worker-shop-queue';
    expect(fn () => app(WorkerSupervisor::class)->applySaved($worker, clone $worker))->toThrow(WorkerControlException::class);
    expect($worker->fresh()->program)->toBeNull()
        ->and(V7WorkerHost::$files[$oldPath])->toBe($original)
        ->and(V7WorkerHost::$running['sv-worker-shop-queue'])->toBeTrue()
        ->and(V7WorkerHost::$files)->toHaveCount(1);
});

it('refuses a second allocation while the host-local lock is held', function () {
    $handle = fopen(storage_path('framework/cache/worker-program.lock'), 'c');
    flock($handle, LOCK_EX);
    try {
        $worker = v7LayoutWorker($this->workerApplication);
        expect(fn () => app(WorkerSupervisor::class)->applySaved($worker))->toThrow(WorkerControlException::class);
        expect(V7WorkerHost::$files)->toBe([]);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('tracks a canonical single-program v7 file when Sync adopts it', function () {
    $item = [
        'attributes' => ['application_id' => $this->workerApplication->id, 'name' => 'queue', 'command' => '/bin/sleep 600', 'kind' => 'custom', 'directory' => $this->workerApplication->rootPath(), 'processes' => 1],
        'evidence' => ['source' => 'supervisor', 'program' => 'queue', 'path' => '/isolated-supervisor/queue.conf', 'program_count' => 1],
    ];
    $worker = app(WorkerDiscoverer::class)->adopt($item);
    expect($worker->program)->toBe('queue')->and($worker->enabled)->toBeFalse();
});

it('does not claim a shared supervisor file or a traversal program', function (string $program, int $count) {
    $worker = app(WorkerDiscoverer::class)->adopt([
        'attributes' => ['application_id' => $this->workerApplication->id, 'name' => 'Imported', 'command' => '/bin/sleep 600', 'kind' => 'custom', 'directory' => $this->workerApplication->rootPath(), 'processes' => 1],
        'evidence' => ['source' => 'supervisor', 'program' => $program, 'path' => '/isolated-supervisor/'.$program.'.conf', 'program_count' => $count],
    ]);
    expect($worker->program)->toBeNull();
})->with([['queue', 2], ['../outside', 1]]);

it('rolls back to the old group when writing the newly allocated config fails', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $oldPath = v7LayoutLegacy($worker);
    $original = V7WorkerHost::$files[$oldPath];
    V7WorkerHost::$fail = 'tee /isolated-supervisor/queue.conf';
    expect(fn () => app(WorkerSupervisor::class)->applySaved($worker, clone $worker))->toThrow(ProvisioningFailedException::class);
    expect($worker->fresh()->program)->toBeNull()
        ->and(V7WorkerHost::$files)->toBe([$oldPath => $original])
        ->and(V7WorkerHost::$running['sv-worker-shop-queue'])->toBeTrue();
});

it('reserves a stored program even if its file is absent', function () {
    $first = v7LayoutWorker($this->workerApplication);
    $first->forceFill(['program' => 'emails'])->save();
    $second = v7LayoutWorker($this->workerApplication, 'Emails');
    app(WorkerSupervisor::class)->applySaved($second);
    expect($second->program)->toBe('emails-2');
});

it('does not let a request mass-assign the internal program', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $worker->fill(['program' => '../foreign']);
    expect($worker->program)->toBeNull();
});

it('reserves a loaded foreign program even when its config is missing', function () {
    V7WorkerHost::$running['queue'] = true;
    $worker = v7LayoutWorker($this->workerApplication);
    app(WorkerSupervisor::class)->applySaved($worker);
    expect($worker->program)->toBe('queue-2')
        ->and(V7WorkerHost::$running['queue'] ?? null)->toBeTrue();
});

it('restores an operator-stopped legacy worker without starting it after failure', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $oldPath = v7LayoutLegacy($worker, false);
    $original = V7WorkerHost::$files[$oldPath];
    V7WorkerHost::$fail = 'tee /isolated-supervisor/queue.conf';
    expect(fn () => app(WorkerSupervisor::class)->applySaved($worker, clone $worker))->toThrow(ProvisioningFailedException::class);
    expect($worker->fresh()->program)->toBeNull()
        ->and(V7WorkerHost::$files)->toBe([$oldPath => $original])
        ->and(V7WorkerHost::$running['sv-worker-shop-queue'])->toBeFalse();
});

it('discovers and adopts a canonical v7 file with an indented program header', function () {
    // A site type with a Workers screen: Sync attributes workers to no other
    // (FS-C22 #14). The fixture's `custom` is not a registered type.
    $this->workerApplication->forceFill(['site_type' => 'php'])->save();
    $root = $this->workerApplication->rootPath();
    Process::fake(function ($process) use ($root) {
        $args = (array) $process->command;
        if ($args[0] === 'find' && $args[1] === '/isolated-supervisor') {
            return Process::result(output: "/isolated-supervisor/queue.conf\n");
        }
        if ($args[0] === 'cat') {
            return Process::result(output: " \t[program:queue]\ncommand=/bin/sleep 600\ndirectory=".$root."\nnumprocs=1\n");
        }

        return Process::result();
    });
    $discoverer = app(WorkerDiscoverer::class);
    $items = $discoverer->discover(new SyncRun);
    expect($items)->toHaveCount(1)
        ->and($items[0]['evidence']['program'])->toBe('queue');
    $worker = $discoverer->adopt($items[0]);
    expect($worker->program)->toBe('queue')->and($worker->enabled)->toBeFalse();
});

it('supports a numeric zero program as a real v7 identity', function () {
    $worker = v7LayoutWorker($this->workerApplication, '0');
    $supervisor = app(WorkerSupervisor::class);
    $supervisor->applySaved($worker);
    expect($worker->program)->toBe('0')
        ->and($supervisor->program($worker))->toBe('0')
        ->and($supervisor->logFile($worker))->toEndWith('/logs/supervisor-0.log');
});

it('does not backfill an adopted v7 program merely because its record is disabled', function () {
    $worker = app(WorkerDiscoverer::class)->adopt([
        'attributes' => ['application_id' => $this->workerApplication->id, 'name' => 'queue', 'command' => '/bin/sleep 600', 'kind' => 'custom', 'directory' => $this->workerApplication->rootPath(), 'processes' => 1],
        'evidence' => ['source' => 'supervisor', 'program' => 'queue', 'path' => '/isolated-supervisor/queue.conf', 'program_count' => 1],
    ]);
    $worker->load('application.systemUser');
    V7WorkerHost::$files['/isolated-supervisor/queue.conf'] = "[program:queue]\ncommand=/bin/sleep 600\nautostart=true\n";
    V7WorkerHost::$running['queue'] = true;
    $files = V7WorkerHost::$files;
    expect(app(WorkerSupervisor::class)->refreshDisabled($worker))->toBeFalse()
        ->and(V7WorkerHost::$files)->toBe($files)
        ->and(V7WorkerHost::$running['queue'])->toBeTrue();
});

it('removes an already-stopped group and allows an already-absent retry', function () {
    $worker = v7LayoutWorker($this->workerApplication);
    $worker->forceFill(['program' => 'queue'])->save();
    V7WorkerHost::$files['/isolated-supervisor/queue.conf'] = app(WorkerSupervisor::class)->render($worker);
    V7WorkerHost::$running['queue'] = false;
    $supervisor = app(WorkerSupervisor::class);
    $supervisor->remove($worker);
    expect(V7WorkerHost::$files)->toBe([])->and(V7WorkerHost::$running)->toBe([]);
    V7WorkerHost::$commands = [];
    $supervisor->remove($worker);
    expect(V7WorkerHost::$commands)->not->toContain('supervisorctl update queue');
});

it('tracks a numeric zero program adopted from v7 without legacy fallback', function () {
    $worker = app(WorkerDiscoverer::class)->adopt([
        'attributes' => ['application_id' => $this->workerApplication->id, 'name' => 'Zero', 'command' => '/bin/sleep 600', 'kind' => 'custom', 'directory' => $this->workerApplication->rootPath(), 'processes' => 1],
        'evidence' => ['source' => 'supervisor', 'program' => '0', 'path' => '/isolated-supervisor/0.conf', 'program_count' => 1],
    ]);
    expect($worker->hasProgram())->toBeTrue()
        ->and(app(WorkerSupervisor::class)->program($worker))->toBe('0');
});
