<?php

namespace App\Jobs;

use App\Actions\Server\Application\PullStaging;
use App\Actions\Server\Application\PushStaging;
use App\Exceptions\Server\Application\StagingBackupFailedException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Models\StagingRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one staging push or update-from-live (RC-16, 2026-10-20).
 *
 * Both used to run inside the request, and nginx stops waiting after 300 s:
 * a large site — or a push that backs up first — answered 504 while the copy
 * carried on, with nothing able to say afterwards how it ended.
 *
 * A failure is recorded as the body and status the old endpoint answered
 * with: every exception here already renders its own response (staging
 * failed / rollback failed / backup failed, a busy lock as a 422), so the run
 * keeps exactly what the user would have read.
 */
class RunStagingSync implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId, public int $applicationId, public ?int $actorId = null)
    {
        $this->timeout = self::timeoutSeconds();
    }

    /**
     * Long enough for a backup first and then the copy. StagingManager holds
     * its own lock for an hour; the backup before a push can take longer.
     */
    public static function timeoutSeconds(): int
    {
        return (int) config('server.staging.job_timeout', 7200);
    }

    /** One per live site: a push and a pull of the same pair touch the same files. */
    public function uniqueId(): string
    {
        return 'staging-sync-'.$this->applicationId;
    }

    public function handle(PushStaging $push, PullStaging $pull): void
    {
        $run = StagingRun::find($this->runId);
        $production = Application::with('systemUser')->find($this->applicationId);

        if ($run === null) {
            return;
        }

        if ($production === null) {
            $run->update(['status' => StagingRun::FAILED, 'error' => ['message' => __('errors/application.staging_missing')], 'error_status' => 422, 'finished_at' => now()]);

            return;
        }

        $run->update(['status' => StagingRun::RUNNING, 'started_at' => now()]);

        try {
            if ($run->kind === 'push') {
                $backup = $push->execute($production, $run->mode, $run->backup, $this->actor(), $run->safety_copy !== false);
                $run->update(['backup_id' => $backup?->id]);
            } else {
                $pull->execute($production, $run->mode, $this->actor(), $run->safety_copy !== false);
            }

            $run->update(['status' => StagingRun::SUCCEEDED, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $this->recordFailure($run, $e);
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = StagingRun::find($this->runId);

        if ($run !== null && in_array($run->status, [StagingRun::QUEUED, StagingRun::RUNNING], true)) {
            $this->recordFailure($run, $e);
        }
    }

    private function recordFailure(StagingRun $run, ?Throwable $e): void
    {
        [$body, $status] = $this->rendered($e);

        if ($e instanceof StagingBackupFailedException) {
            $run->backup_id = $e->backup->id;
        }

        $run->fill(['status' => StagingRun::FAILED, 'error' => $body, 'error_status' => $status, 'finished_at' => now()])->save();
    }

    /**
     * What the old endpoint would have answered for this exception.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function rendered(?Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return [['message' => $e->getMessage(), 'errors' => $e->errors()], 422];
        }

        if ($e !== null && method_exists($e, 'render')) {
            try {
                $response = $e->render(request());

                if ($response instanceof JsonResponse) {
                    return [(array) $response->getData(true), $response->getStatusCode()];
                }
            } catch (Throwable) {
                // Fall through to the generic answer.
            }
        }

        // Anything else is a crash: the detail goes to the log under a
        // reference, never into a row the API returns.
        $reference = (string) Str::uuid();
        Log::error('staging sync crashed', [
            'reference' => $reference,
            'run' => $this->runId,
            'exception' => $e ? $e::class.': '.$e->getMessage() : null,
        ]);

        return [['message' => __('errors/application.staging_failed'), 'code' => 'staging_failed', 'reference' => $reference], 500];
    }
}
