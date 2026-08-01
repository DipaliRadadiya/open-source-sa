<?php

namespace App\Actions\Server\Database;

use App\Enums\ExportStatus;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Read-only export (dump) of a database to a managed exports dir. Non-
 * destructive — the source database is never touched.
 *
 * Runs inside a queued job rather than a request. A dump of any real database
 * outlives nginx's read timeout, so the request that started it was being told
 * the work had failed while it was still running perfectly well.
 */
class ExportDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Dump `$database`, recording the outcome on `$export`.
     */
    public function execute(Database $database, DatabaseExport $export): DatabaseExport
    {
        $dir = (string) config('server.databases.export_dir');
        File::ensureDirectoryExists($dir, 0700);

        $extension = $this->manager->driver($database->engine) === 'mongo' ? 'archive.gz' : 'sql';
        $file = Str::slug($database->name).'-'.$database->engine.'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.'.$extension;
        $path = rtrim($dir, '/').'/'.$file;

        // Throws DatabaseOperationException on failure; the job catches that
        // and records the reason, so this stays a straight line.
        $this->manager->engine($database->engine)->dump($database->name, $path);

        $export->update([
            'file' => $file,
            'status' => ExportStatus::Completed,
            'size_bytes' => is_file($path) ? (int) filesize($path) : 0,
            'finished_at' => now(),
        ]);

        // Logged on completion rather than when it was asked for, so the entry
        // describes something that actually happened. The actor comes off the
        // row because a queue worker has no session to read one from.
        $this->activityLogger->log(
            'database.exported',
            $database,
            ['name' => $database->name, 'file' => $file],
            actor: $export->user,
        );

        return $export->refresh();
    }
}
