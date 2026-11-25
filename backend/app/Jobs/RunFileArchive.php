<?php

namespace App\Jobs;

use App\Enums\FileArchiveStatus;
use App\Exceptions\Server\Application\FileOperationException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\FileArchiveJob;
use App\Services\Server\Applications\FileBrowser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds or unpacks an archive out of the request cycle.
 *
 * Inline, a large selection hit the web server's request timeout (nginx 300 s, Apache 300 s,
 * OpenLiteSpeed 60 s), which fails above PHP where no cleanup can run. Same shape as
 * `RunDatabaseExport`.
 *
 * One attempt, no retries: a half-written archive racing a second attempt over the same path is
 * worse than a visible failure the user can repeat.
 */
class RunFileArchive implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $archiveJobId)
    {
        // From config so the ceiling and the reservation window derive from one number;
        // QueueTimeoutTest asserts it stays inside `retry_after` on every connection.
        $this->timeout = (int) config('server.files.archive_job_timeout', 21600);
    }

    /**
     * One archive operation per target path at a time.
     *
     * Keyed on the path, not the application: two jobs writing the same `archive.tar.gz` corrupt
     * it silently. Not the row id either: a second request has a different row.
     */
    public function uniqueId(): string
    {
        $job = FileArchiveJob::find($this->archiveJobId);

        return 'file-archive-'.($job?->application_id ?? 0).'-'.md5((string) $job?->target);
    }

    public function handle(FileBrowser $files): void
    {
        $job = FileArchiveJob::with('application')->find($this->archiveJobId);

        if ($job === null) {
            return;
        }

        if ($job->application === null) {
            // The site was deleted between the request and the worker; nothing to fail loudly over.
            $job->update([
                'status' => FileArchiveStatus::Failed,
                'reason' => 'application_missing',
                'finished_at' => now(),
            ]);

            return;
        }

        $job->update(['status' => FileArchiveStatus::Running, 'started_at' => now()]);

        try {
            $files->runArchiveJob($job);

            $job->update([
                'status' => FileArchiveStatus::Completed,
                'size_bytes' => $files->archiveSize($job),
                'finished_at' => now(),
            ]);
        } catch (FileOperationException $e) {
            $job->update([
                'status' => FileArchiveStatus::Failed,
                // `timed_out` gets its own code: nothing is broken, the advice is a smaller selection.
                'reason' => $e->timedOut ? 'timed_out' : 'command_failed',
                'reference' => $e->reference,
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * The job died outright (timeout, killed worker). Without this the row stays `running` forever
     * and the unique lock keeps that path from ever being compressed again.
     */
    public function failed(?Throwable $e): void
    {
        $reference = (string) Str::uuid();

        Log::error('file archive job died at the worker level', [
            'reference' => $reference,
            'archive_job_id' => $this->archiveJobId,
            'exception' => $e ? $e::class.': '.$e->getMessage() : null,
        ]);

        FileArchiveJob::where('id', $this->archiveJobId)
            ->whereIn('status', FileArchiveJob::IN_FLIGHT)
            ->update([
                'status' => FileArchiveStatus::Failed->value,
                'reason' => 'worker',
                'reference' => $reference,
                'finished_at' => now(),
            ]);
    }
}
