<?php

namespace App\Actions\Server\Database;

use App\Enums\ExportStatus;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

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

        $extension = (string) config("server.databases.engines.{$database->engine}.dump_extension");
        $file = Str::slug($database->name).'-'.$database->engine.'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.'.$extension;
        $path = rtrim($dir, '/').'/'.$file;

        // Throws DatabaseOperationException on failure; the job catches that
        // and records the reason, so this stays a straight line.
        $this->manager->engine($database->engine)->dump($database->name, $path);

        if ($this->compresses($export, $extension)) {
            [$file, $path] = $this->compress($file, $path);
        }

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

    /**
     * A user's SQL export is handed over gzipped (Dipali, 2026-10-26):
     * typically 5–10× smaller, and the import takes `.sql.gz` as it is.
     *
     * Not an import's safety copy. That one is loaded straight back with the
     * engine's `restore()`, which reads plain SQL — compressed, the very copy
     * meant to undo a failed import could not be put back. Mongo's archive is
     * already gzipped by mongodump.
     */
    private function compresses(DatabaseExport $export, string $extension): bool
    {
        return $extension === 'sql' && $export->purpose !== DatabaseExport::PURPOSE_IMPORT_SAFETY;
    }

    /**
     * Gzip the dump beside itself and drop the plain one, streamed so a dump
     * larger than PHP's memory limit costs one buffer.
     *
     * A compression that fails keeps the plain dump: it is a complete export,
     * and failing the whole export over its packaging would throw it away.
     *
     * @return array{0: string, 1: string} the file name and path to record
     */
    private function compress(string $file, string $path): array
    {
        $target = $path.'.gz';
        $in = @fopen($path, 'rb');
        $out = @gzopen($target, 'wb6');

        try {
            if ($in === false || $out === false) {
                throw new RuntimeException('cannot open');
            }

            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                // gzwrite reports a failed write (a full disk) as 0 or false.
                if ($chunk === false || ($chunk !== '' && ! gzwrite($out, $chunk))) {
                    throw new RuntimeException('cannot write');
                }
            }

            $closed = gzclose($out);
            $out = false;

            if (! $closed) {
                throw new RuntimeException('cannot finish');
            }
        } catch (RuntimeException) {
            if ($out !== false) {
                gzclose($out);
            }

            @unlink($target);

            return [$file, $path];
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }

        @unlink($path);

        return [$file.'.gz', $target];
    }
}
