<?php

use App\Services\Panel\QueueWorker;
use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\Process;

/**
 * One worker, `high` before `default` (nginx QA #4: a certificate waited 22
 * minutes behind queued installs). The danger is the opposite mistake — a job
 * on a queue no worker reads is never run, silently — so a job goes to `high`
 * only when the running worker is seen to read it.
 */
function fakeRunningWorker(?string $commandLine, string $pid = '4242'): void
{
    Process::fake(function ($process) use ($commandLine, $pid) {
        $command = $process->command;

        if (($command[0] ?? '') === 'systemctl' && in_array('MainPID', $command, true)) {
            return Process::result(output: $commandLine === null ? "0\n" : "{$pid}\n");
        }

        if (($command[0] ?? '') === 'ps') {
            return Process::result(output: (string) $commandLine."\n");
        }

        return Process::result(exitCode: 0);
    });
}

describe('which queue a priority job goes to', function () {
    it('uses high when the running worker reads it', function () {
        fakeRunningWorker('/usr/bin/php8.4 /var/www/panel/backend/artisan queue:work --queue=high,default --sleep=3 --tries=1');

        expect(app(QueueWorker::class)->priorityQueue())->toBe('high');
    });

    it('stays on the default queue for a worker started without --queue', function () {
        // Every server installed before the priority queue: `high` would be
        // accepted, stored and never run.
        fakeRunningWorker('/usr/bin/php8.4 /var/www/panel/backend/artisan queue:work --sleep=3 --tries=1 --max-time=3600');

        expect(app(QueueWorker::class)->priorityQueue())->toBeNull();
    });

    it('stays on the default queue when no worker is running or it cannot be read', function () {
        fakeRunningWorker(null);
        expect(app(QueueWorker::class)->priorityQueue())->toBeNull();

        Process::fake(fn () => Process::result(errorOutput: 'Failed to connect to bus', exitCode: 1));
        expect(app(QueueWorker::class)->priorityQueue())->toBeNull();
    });

    it('reads the space-separated form of --queue too', function () {
        expect(QueueWorker::queuesIn('artisan queue:work --queue high,default'))->toBe(['high', 'default'])
            ->and(QueueWorker::queuesIn('artisan queue:work --sleep=3'))->toBe([])
            ->and(QueueWorker::queuesIn('php-fpm: pool www'))->toBe([]);
    });
});

describe('rewriting the worker unit', function () {
    it('adds the queue list to a bare queue:work', function () {
        $unit = "[Service]\nExecStart=/usr/bin/php8.4 /var/www/panel/backend/artisan queue:work --sleep=3 --tries=1 --max-time=3600\nRestart=always\n";

        expect(QueueWorker::withPriority($unit))
            ->toBe("[Service]\nExecStart=/usr/bin/php8.4 /var/www/panel/backend/artisan queue:work --queue=high,default --sleep=3 --tries=1 --max-time=3600\nRestart=always\n");
    });

    it('leaves a unit that already reads high, or names its own queues', function () {
        expect(QueueWorker::withPriority("ExecStart=php artisan queue:work --queue=high,default --sleep=3\n"))->toBeNull()
            ->and(QueueWorker::withPriority("ExecStart=php artisan queue:work --queue=emails\n"))->toBeNull()
            ->and(QueueWorker::withPriority("ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize\n"))->toBeNull();
    });

    it('rewrites the unit file and reloads systemd, without restarting the worker', function () {
        $dir = sys_get_temp_dir().'/qw-'.uniqid();
        mkdir($dir);
        config(['server.applications.systemd_dir' => $dir, 'panel_update.services.queue' => 'panel-queue.service']);
        file_put_contents("{$dir}/panel-queue.service", "[Service]\nExecStart=/usr/bin/php artisan queue:work --sleep=3\n");
        Process::fake();

        $this->artisan('panel:queue-worker')->assertSuccessful();

        expect(file_get_contents("{$dir}/panel-queue.service"))->toContain('queue:work --queue=high,default --sleep=3');
        Process::assertRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
        Process::assertNotRan(fn ($process) => in_array('restart', (array) $process->command, true));

        // Idempotent: the second run changes nothing and reloads nothing.
        // (Counted by hand: a second Process::fake keeps the first's record.)
        $GLOBALS['queueWorkerRuns'] = 0;
        Process::fake(function () {
            $GLOBALS['queueWorkerRuns']++;

            return Process::result();
        });
        $this->artisan('panel:queue-worker')->expectsOutputToContain('is up to date')->assertSuccessful();
        expect($GLOBALS['queueWorkerRuns'])->toBe(0);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    });

    it('does nothing where there is no unit', function () {
        config(['server.applications.systemd_dir' => sys_get_temp_dir().'/qw-absent-'.uniqid()]);
        Process::fake();

        $this->artisan('panel:queue-worker')->assertSuccessful();

        Process::assertNothingRan();
    });
});

describe('the installer and the updater agree', function () {
    it('writes the worker with exactly QueueWorker::QUEUES', function () {
        $install = (string) file_get_contents(base_path('../install.sh'));

        expect(preg_match('/^ExecStart=.*artisan queue:work (.*)$/m', $install, $match))->toBe(1)
            ->and(QueueWorker::queuesIn('queue:work '.$match[1]))->toBe(explode(',', QueueWorker::QUEUES));
    });

    it('names no queue in app code that the worker does not read', function () {
        // A literal onQueue('x') for an x the worker does not drain is the
        // backups bug again. Priority jobs go through QueueWorker instead.
        $read = explode(',', QueueWorker::QUEUES);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        $scanned = 0;

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            preg_match_all("/(?:onQueue\\(|->queue\\s*=\\s*|public \\\$queue\\s*=\\s*)'([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $queue) {
                expect($read)->toContain($queue);
            }
        }

        expect($scanned)->toBeGreaterThan(100);
    });

    it('updates the unit before the services restart', function () {
        $steps = UpdateScript::STEPS;

        expect(array_search('configure_queue_worker', $steps, true))
            ->toBeLessThan(array_search('restart_services', $steps, true));
    });
});
