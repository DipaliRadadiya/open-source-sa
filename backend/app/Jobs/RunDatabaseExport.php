<?php

namespace App\Jobs;

use App\Actions\Server\Database\ExportDatabase;
use App\Enums\ExportStatus;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\DatabaseExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Dumps a database out of the request cycle.
 *
 * It used to run inline. `mysqldump` is given ten minutes, but nginx gives the
 * request five (`fastcgi_read_timeout 300`), so on any database big enough to
 * matter the browser was shown a failure while the dump carried on and quietly
 * succeeded. The user was told the wrong thing about work that had worked.
 *
 * One attempt, no retries. A dump that failed halfway has already written a
 * partial file, and a second attempt racing the first over the same path is a
 * worse outcome than a failure the user can see and repeat deliberately.
 */
class RunDatabaseExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Comfortably beyond the dump's own 600s ceiling, so the process decides
     * when it has taken too long rather than the worker killing it partway and
     * leaving a truncated file that looks complete.
     */
    public int $timeout = 900;

    public function __construct(public int $exportId) {}

    public function handle(ExportDatabase $action): void
    {
        $export = DatabaseExport::find($this->exportId);

        if ($export === null || $export->database === null) {
            // The database was dropped between the request and the worker.
            // Nothing to dump, and nothing worth failing loudly over.
            $export?->update([
                'status' => ExportStatus::Failed,
                'reason' => 'database_missing',
                'finished_at' => now(),
            ]);

            return;
        }

        $export->update(['status' => ExportStatus::Running, 'started_at' => now()]);

        try {
            $action->execute($export->database, $export);
        } catch (DatabaseOperationException $e) {
            $export->update([
                'status' => ExportStatus::Failed,
                'reason' => 'dump_failed',
                'reference' => $e->reference,
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * The job died outright — timeout, or the worker was killed. Without this
     * the row sits at `running` forever and the screen shows a spinner that
     * will never resolve.
     */
    public function failed(?Throwable $e): void
    {
        DatabaseExport::where('id', $this->exportId)
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
            ->update([
                'status' => ExportStatus::Failed->value,
                'reason' => 'worker',
                'finished_at' => now(),
            ]);
    }
}
