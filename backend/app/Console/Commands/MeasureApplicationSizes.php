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
 * The panel measures a site when it is provisioned, deployed or browsed, and
 * backfills the never-measured ones as the list is opened — none of which
 * helps an operator who wants the numbers right now, or who is trying to find
 * out why one site refuses to produce one.
 *
 * Synchronous on purpose, unlike everything else that measures. The queued job
 * exists so a `du` over forty thousand inodes never sits inside a web request;
 * at a shell prompt that reasoning inverts — somebody is standing there
 * waiting for the answer, and a command that returns instantly having queued
 * work they cannot see is not an answer. It also makes this the honest
 * diagnostic: if this succeeds where the queue does not, the problem is the
 * worker rather than the measuring.
 *
 * Failures are reported per site and never stop the run. One unreadable site
 * must not deny the other forty their numbers — which is exactly what happened
 * on a real server, where a single checkout with unreadable files sat at "Not
 * measured" beside seven that were fine.
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
            // The default is the useful one: fill in what is missing. Asking
            // for everything walks every inode on the server, which is a
            // deliberate act rather than a default.
            ->when(
                ! $this->option('all') && ! $this->option('id') && $stale === null,
                fn ($query) => $query->whereNull('directory_size_bytes'),
            )
            // Zero means no staleness filter — every site, however recently it
            // was measured. Not "measured before this instant", which is what
            // subMinutes(0) computes and which quietly skips anything measured
            // inside the current second.
            ->when((int) $stale > 0, fn ($query) => $query
                ->where(fn ($q) => $q
                    ->whereNull('directory_size_updated_at')
                    ->orWhere('directory_size_updated_at', '<', now()->subMinutes((int) $stale))))
            // Least-recently-measured first whenever this is a sweep, so a
            // capped run takes a different slice each time and every site
            // comes round rather than the first N being walked forever.
            ->when($stale !== null, fn ($query) => $query
                ->orderByRaw('directory_size_updated_at IS NOT NULL')
                ->orderBy('directory_size_updated_at'))
            ->when($stale === null, fn ($query) => $query->orderBy('id'))
            // Zero means no ceiling — every candidate, which is what the
            // scheduled sweep asks for by default. A positive value is the
            // bound for an operator who does not want one tick walking every
            // inode on a server with hundreds of sites.
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
                // The reference, because the exception itself carries no
                // message — the failing command and its stderr are in the
                // server-ops log under that id.
                $reference = $e instanceof ServerOperationException ? $e->reference : '—';
                $this->warn("  {$application->name}: could not be measured (reference {$reference})");
            }
        }

        $this->info("Measured {$measured} site(s).");

        if ($failed > 0) {
            // Non-zero so a scripted caller notices, but only after every site
            // has been attempted.
            $this->warn("{$failed} site(s) failed. Search the server-ops log for the references above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
