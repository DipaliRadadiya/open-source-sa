<?php

namespace App\Actions\Server\Database;

use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Read-only export (dump) of a database to a managed exports dir. Non-
 * destructive — the source database is never touched.
 */
class ExportDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{file: string, size_bytes: int, created_at: string}
     */
    public function execute(Database $database): array
    {
        $dir = (string) config('server.databases.export_dir');
        File::ensureDirectoryExists($dir, 0700);

        $extension = $this->manager->driver($database->engine) === 'mongo' ? 'archive.gz' : 'sql';
        $file = Str::slug($database->name).'-'.$database->engine.'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.'.$extension;
        $path = rtrim($dir, '/').'/'.$file;

        $this->manager->engine($database->engine)->dump($database->name, $path);

        $this->activityLogger->log('database.exported', $database, ['name' => $database->name, 'file' => $file]);

        return [
            'file' => $file,
            'size_bytes' => is_file($path) ? (int) filesize($path) : 0,
            'created_at' => now()->format('d-m-Y H:i:s'),
        ];
    }
}
