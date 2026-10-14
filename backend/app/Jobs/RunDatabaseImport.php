<?php

namespace App\Jobs;

use App\Actions\Server\Database\ImportDatabase;
use App\Enums\ImportStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Models\DatabaseImport;
use App\Services\Server\Databases\Imports\ImportFiles;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import an uploaded file into a database ({@see ImportDatabase}).
 *
 * Queued because it is three passes over a database that can be gigabytes —
 * the safety copy, the load, and putting the copy back if the load fails — and
 * any of those outlives a request. One attempt: a second run would back up the
 * half-imported database over the real copy.
 *
 * The timeout is the backups' own: three import-timeouts fit inside it, and the
 * queue's reservation window is derived from it (config/queue.php), so a long
 * import is never handed to a second worker while the first is still loading.
 */
class RunDatabaseImport implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $importId,
        public int $databaseId,
    ) {
        $this->timeout = (int) config('server.backups.job_timeout', 21600);
    }

    /** One import per database at a time — and not alongside its export (checked at start). */
    public function uniqueId(): string
    {
        return 'database-import-'.$this->databaseId;
    }

    public function handle(ImportDatabase $action): void
    {
        $import = DatabaseImport::find($this->importId);

        if ($import === null || $import->status !== ImportStatus::Queued) {
            return;
        }

        $action->execute($import);
    }

    /**
     * The worker died outright — timeout or killed. Nothing put the safety copy
     * back, so the row has to say where the run was: before `importing` the
     * database was never touched; from it on, the copy is in Exports.
     */
    public function failed(?Throwable $e): void
    {
        $reference = (string) Str::uuid();

        Log::error('database import job died at the worker level', [
            'reference' => $reference,
            'import_id' => $this->importId,
            'exception' => $e ? $e::class.': '.$e->getMessage() : null,
        ]);

        $import = DatabaseImport::find($this->importId);

        if ($import === null) {
            return;
        }

        app(ImportFiles::class)->delete($import);

        if (in_array($import->status->value, DatabaseImport::IN_FLIGHT, true)) {
            $import->update([
                'status' => ImportStatus::Failed,
                'reason' => 'worker',
                'reference' => $reference,
                'finished_at' => now(),
            ]);
        }
    }
}
