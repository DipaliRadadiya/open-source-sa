<?php

namespace App\Console\Commands;

use App\Exceptions\Server\ServerOperationException;
use App\Models\Application;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-measure what sites take up on disk, from the shell.
 *
 * For an operator who wants the numbers now, or is finding out why one site will not produce one.
 *
 * Synchronous on purpose, unlike the queued job (which keeps a `du` out of web requests): at a
 * shell prompt someone is waiting for the answer. It is also the honest diagnostic: if this
 * succeeds where the queue does not, the problem is the worker, not the measuring.
 *
 * Failures are reported per site and never stop the run: one unreadable site must not deny the
 * others their numbers.
 */
class MeasureApplicationSizes extends Command
{
    protected $signature = 'applications:measure-sizes
                            {--id=* : Only these application ids}
                            {--all : Include sites that already have a size}
                            {--stale= : Only sites not measured in this many minutes}
                            {--limit= : Measure at most this many sites}';

    protected $description = 'Re-measure how much disk each site uses.';

    public function handle(FileBrowser $files): int
    {
        $stale = $this->option('stale');
        $limit = $this->option('limit');

        $applications = Application::query()
            ->when($this->option('id'), fn ($query, $ids) => $query->whereIn('id', $ids))
            // Default: fill in what is missing. Re-measuring everything walks every inode.
            ->when(
                ! $this->option('all') && ! $this->option('id') && $stale === null,
                fn ($query) => $query->whereNull('directory_size_bytes'),
            )
            // Zero means no staleness filter (subMinutes(0) would skip anything measured in the
            // current second).
            ->when((int) $stale > 0, fn ($query) => $query
                ->where(fn ($q) => $q
                    ->whereNull('directory_size_updated_at')
                    ->orWhere('directory_size_updated_at', '<', now()->subMinutes((int) $stale))))
            // Least-recently-measured first, so a capped run takes a different slice each time.
            ->when($stale !== null, fn ($query) => $query
                ->orderByRaw('directory_size_updated_at IS NOT NULL')
                ->orderBy('directory_size_updated_at'))
            ->when($stale === null, fn ($query) => $query->orderBy('id'))
            // Zero means no ceiling: every candidate.
            ->when((int) $limit > 0, fn ($query) => $query->limit((int) $limit))
            ->get();

        if ($applications->isEmpty()) {
            $this->info('Nothing to measure.');

            return self::SUCCESS;
        }

        $measured = 0;
        $failed = 0;

        foreach ($applications as $application) {
            try {
                $size = $files->applicationSize($application, refresh: true);
                $measured++;
                $this->line("  {$application->name}: {$size['size_human']}");
            } catch (Throwable $e) {
                $failed++;
                // The exception carries no message; the command and stderr are in the server-ops
                // log under this reference.
                $reference = $e instanceof ServerOperationException ? $e->reference : '—';
                $this->warn("  {$application->name}: could not be measured (reference {$reference})");
            }
        }

        $this->info("Measured {$measured} site(s).");

        if ($failed > 0) {
            // Non-zero so a scripted caller notices, but only after every site was attempted.
            $this->warn("{$failed} site(s) failed. Search the server-ops log for the references above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
