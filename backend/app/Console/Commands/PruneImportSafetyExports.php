<?php

namespace App\Console\Commands;

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use Illuminate\Console\Command;

/**
 * Removes the copy a database import takes before replacing anything, a week
 * after it was taken (RC-14, operator's call 2026-10-20).
 *
 * Each is a full dump of the database on the same disk as the database, and
 * nothing removed them: six test imports into one database left 81 MB. Until
 * they go they are listed with the database's exports, so the user can still
 * download or delete one.
 *
 * Kept however old it is: the copy of an import that failed and was not put
 * back — the database may now be broken and that copy the good version — and
 * the copy of an import still running.
 */
class PruneImportSafetyExports extends Command
{
    public const KEEP_DAYS = 7;

    protected $signature = 'exports:prune-import-safety';

    protected $description = "Remove database imports' safety copies older than a week";

    public function handle(): int
    {
        $stillNeeded = DatabaseImport::query()
            ->whereNotNull('safety_export_id')
            ->where(fn ($query) => $query
                ->whereIn('status', [ImportStatus::Uploading->value, ImportStatus::Queued->value, ImportStatus::Running->value])
                ->orWhere(fn ($failed) => $failed->where('status', ImportStatus::Failed->value)->where('restored', false)))
            ->pluck('safety_export_id');

        $expired = DatabaseExport::query()
            ->where('purpose', DatabaseExport::PURPOSE_IMPORT_SAFETY)
            ->whereIn('status', [ExportStatus::Completed->value, ExportStatus::Failed->value])
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->whereNotIn('id', $stillNeeded)
            ->get();

        $expired->each(fn (DatabaseExport $export) => $export->deleteWithFile());

        $this->info("Removed {$expired->count()} import safety cop(ies).");

        return self::SUCCESS;
    }
}
