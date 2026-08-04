<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Is the panel's own database reachable, and is its schema current?
 *
 * Pending migrations are the interesting half. A panel updated by pulling code
 * without running migrate looks completely healthy right up to the first
 * request that touches a new column, then fails in a way that looks like a
 * code bug.
 */
class DatabaseCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'database';
    }

    public function run(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                // The driver name is safe; the DSN and credentials are not.
                'detail' => 'cannot connect ('.config('database.default').')',
                'fix' => 'doctor.fixes.database_unreachable',
            ];
        }

        $pending = $this->pendingMigrations();

        if ($pending > 0) {
            return [
                'status' => 'fail',
                'detail' => $pending.' pending migration'.($pending === 1 ? '' : 's'),
                'fix' => 'doctor.fixes.database_pending',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => config('database.default').', schema current',
            'fix' => null,
        ];
    }

    /**
     * Counted by comparing files on disk against the migrations table, rather
     * than shelling out to `artisan migrate:status` — this already runs inside
     * artisan, and a subprocess would double the boot cost of a check that
     * exists to be cheap.
     */
    private function pendingMigrations(): int
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            // No migrations table at all: everything is pending.
            return count(File::files(database_path('migrations')));
        }

        $files = collect(File::files(database_path('migrations')))
            ->map(fn ($file): string => $file->getFilenameWithoutExtension());

        return $files->diff($ran)->count();
    }
}
