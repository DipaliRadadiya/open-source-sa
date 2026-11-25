<?php

namespace App\Jobs;

use App\Actions\Server\Database\ExportDatabase;
use App\Enums\ExportStatus;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\DatabaseExport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dumps a database out of the request cycle: `mysqldump` gets ten minutes, but nginx cuts the
 * request at five (`fastcgi_read_timeout 300`).
 *
 * One attempt, no retries: a failed dump leaves a partial file, and a second attempt racing it
 * over the same path is worse than a visible failure the user repeats deliberately.
 */
class RunDatabaseExport implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    /** Beyond the dump's own 600s ceiling, so the worker never kills it and leaves a truncated file. */
    public int $timeout = 900;

    public function __construct(
        public int $exportId,
        /**
         * Lets {@see uniqueId()} answer without a query (a deleted row would collapse every export
         * onto one lock). Required, no default: a default would queue forgetful callers together.
         */
        public int $databaseId,
    ) {}

    /**
     * One dump per database at a time: a second run writes the same bytes to the same disk.
     */
    public function uniqueId(): string
    {
        return 'database-export-'.$this->databaseId;
    }

    public function handle(ExportDatabase $action): void
    {
        $export = DatabaseExport::find($this->exportId);

        if ($export === null || $export->database === null) {
            // The database was dropped between the request and the worker: nothing to dump.
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

    /** The job died outright (timeout, worker killed); otherwise the row stays `running` forever. */
    public function failed(?Throwable $e): void
    {
        $reference = (string) Str::uuid();

        Log::error('database export job died at the worker level', [
            'reference' => $reference,
            'export_id' => $this->exportId,
            'exception' => $e ? $e::class.': '.$e->getMessage() : null,
        ]);

        DatabaseExport::where('id', $this->exportId)
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
            ->update([
                'status' => ExportStatus::Failed->value,
                'reason' => 'worker',
                'reference' => $reference,
                'finished_at' => now(),
            ]);
    }
}
