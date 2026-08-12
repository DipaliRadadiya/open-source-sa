<?php

namespace App\Console\Commands;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Models\SyncRun;
use App\Services\Server\Sync\ServerSync;
use Illuminate\Console\Command;

/**
 * The same sync, for the operator who is already in a shell.
 *
 * Runs inline rather than queueing: someone who typed this is watching it, and
 * a command that returns immediately and tells them to go and look at a web
 * page would be worse than useless right after a migration.
 *
 * Defaults to a preview. `--apply` is the flag you have to mean.
 */
class SyncServer extends Command
{
    protected $signature = 'server:sync {--apply : Adopt what is found, instead of only listing it}';

    protected $description = 'Read this server into the panel — users, keys, and everything else it can find';

    public function handle(ServerSync $sync): int
    {
        $mode = $this->option('apply') ? SyncMode::Apply : SyncMode::Preview;

        $run = SyncRun::create([
            'mode' => $mode,
            'status' => SyncStatus::Pending,
            'options' => ['only' => [], 'include_firewall' => false],
        ]);

        $this->info($mode === SyncMode::Apply
            ? 'Reading the server and adopting what it finds…'
            : 'Reading the server. Nothing will be changed — pass --apply to adopt.');

        $run = $sync->run($run);

        foreach ($run->items()->orderBy('id')->get() as $item) {
            $line = sprintf('%-14s %-40s %s', $item->resource_type, $item->resource_key, $item->action->value);

            match ($item->action->value) {
                'failed' => $this->error($line),
                'skipped' => $this->warn($line.($item->reason ? ' — '.$item->reason : '')),
                default => $this->line($line),
            };
        }

        $this->newLine();

        foreach ($run->totals ?? [] as $type => $counts) {
            $this->info(sprintf(
                '%s: %d found, %d adopted, %d skipped, %d failed',
                $type,
                $counts['found'] ?? 0,
                $counts['adopted'] ?? 0,
                $counts['skipped'] ?? 0,
                $counts['failed'] ?? 0,
            ));
        }

        // Exit 0 even with failed items: they are recorded and printed, and a
        // non-zero exit would abort an install script over one unreadable
        // authorized_keys line.
        return self::SUCCESS;
    }
}
