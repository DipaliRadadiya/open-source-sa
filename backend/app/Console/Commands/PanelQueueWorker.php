<?php

namespace App\Console\Commands;

use App\Services\Panel\QueueWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Make the panel's queue worker read the priority queue.
 *
 * install.sh writes the unit with `--queue=high,default`; servers installed
 * before that run a bare `queue:work`, which reads `default` only. This adds
 * the list to the existing unit and reloads systemd. It never restarts the
 * worker — a restart ends the job it is running — so the change takes effect
 * at the next restart, which every update and deploy already does.
 *
 * Until then nothing is lost: jobs go to `high` only when the running worker
 * is seen to read it (QueueWorker::priorityQueue()).
 *
 * Runs as root (the unit is root's). Called by the updater's
 * `configure_queue_worker` step and the manual deploy runbook. Idempotent.
 */
class PanelQueueWorker extends Command
{
    protected $signature = 'panel:queue-worker
        {--dry-run : Report whether the unit is current, without writing}';

    protected $description = 'Make the queue worker unit read the priority queue first';

    public function handle(QueueWorker $worker): int
    {
        $path = $worker->unitPath();

        if (! is_file($path)) {
            $this->warn("{$path} does not exist; nothing to change.");

            return self::SUCCESS;
        }

        $current = (string) file_get_contents($path);
        $desired = QueueWorker::withPriority($current);

        if ($desired === null) {
            $queues = QueueWorker::queuesIn($current);

            in_array(QueueWorker::PRIORITY, $queues, true) || ! str_contains($current, 'queue:work')
                ? $this->info("{$path} is up to date.")
                : $this->warn("{$path} names its own queues (".implode(',', $queues).'); left as it is. Add '.QueueWorker::PRIORITY.' for certificates to go first.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$path} would be updated to read ".QueueWorker::QUEUES.'.');

            return self::SUCCESS;
        }

        // Temporary file beside the unit and a rename: a half-written unit is
        // a worker systemd will not start.
        $temporary = $path.'.panel-tmp';

        if (file_put_contents($temporary, $desired) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            $this->error("Could not write {$path}; the worker keeps its current queues.");

            return self::FAILURE;
        }

        $reload = Process::timeout(30)->run(['systemctl', 'daemon-reload']);

        if ($reload->failed()) {
            $this->warn('systemctl daemon-reload failed: '.trim($reload->errorOutput()));
        }

        $this->info("{$path} now reads ".QueueWorker::QUEUES.' (applies at the next worker restart).');

        return self::SUCCESS;
    }
}
