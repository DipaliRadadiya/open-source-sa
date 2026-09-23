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
 * It used to run inline, against a 60 s ceiling every file operation shared.
 * That made a large selection impossible rather than slow: `tar` was killed at
 * the minute mark, leaving a partial archive, and the retry was then refused
 * by the does-it-already-exist guard — so a size problem was reported as a
 * filename collision.
 *
 * Raising the ceiling was not available. It belongs to the web server, and the
 * three the panel installs disagree: nginx `fastcgi_read_timeout 300`, Apache
 * `ProxyTimeout 300`, OpenLiteSpeed `initTimeout 60`. A timeout tuned past the
 * lowest fails *above* PHP, where no cleanup of ours can run.
 *
 * `RunDatabaseExport` reached the same conclusion for the same reason, and the
 * shape here is deliberately its shape.
 *
 * One attempt, no retries. A half-written archive racing a second attempt over
 * the same path is a worse outcome than a failure the user can see and repeat
 * deliberately.
 */
class RunFileArchive implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $archiveJobId)
    {
        // Read from config rather than declared, so the ceiling and the
        // reservation window stay derivable from one number. The guard in
        // QueueTimeoutTest asserts this stays inside `retry_after` on every
        // connection — a property that was violated once already by raising
        // one of the two literals alone.
        $this->timeout = (int) config('server.files.archive_job_timeout', 21600);
    }

    /**
     * One archive operation per target path at a time.
     *
     * Keyed on the path rather than the application: two people compressing
     * two different folders on one site are not in conflict, but two jobs
     * writing the same `archive.tar.gz` produce a corrupt file and no error.
     * Before this, a double-submit started as many jobs as it was clicked.
     *
     * The row id is not usable here — the lock has to be the same for a second
     * *request*, which has a different row.
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
            // The site was deleted between the request and the worker. The
            // archive would land nowhere; nothing worth failing loudly over.
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
                // `timed_out` is worth its own code: nothing is broken, the
                // work outlived even the job's ceiling, and the advice is a
                // smaller selection rather than a retry.
                'reason' => $e->timedOut ? 'timed_out' : 'command_failed',
                'reference' => $e->reference,
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * The job died outright — timeout, or the worker was killed. Without this
     * the row sits at `running` forever, the screen shows a spinner that never
     * resolves, and because the unique lock is keyed on the target path, that
     * path can never be compressed again.
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
