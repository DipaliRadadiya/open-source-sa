<?php

namespace App\Console\Commands;

use App\Services\Server\Databases\PgsqlEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Dump the panel's own database before an update migrates it.
 *
 * Called by the update script because only Laravel knows which driver (sqlite, mysql,
 * mariadb, postgres) this install uses.
 *
 * Every supported driver must have an arm here: `UpdateScript` runs this step under `set -e`
 * with a rollback trap, so a driver that falls through to `unsupported()` makes every update
 * fail and roll back.
 */
class BackupPanelDatabase extends Command
{
    protected $signature = 'panel:backup-database {--path= : Directory to write the dump into}';

    protected $description = "Back up the panel's own database";

    public function handle(): int
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        $dir = $this->option('path') ?: storage_path('app/panel-backups');

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd-His');

        $code = match ($driver) {
            'sqlite' => $this->dumpSqlite($dir, $stamp),
            'mysql', 'mariadb' => $this->dumpMysql($connection, $dir, $stamp),
            'pgsql' => $this->dumpPostgres($connection, $dir, $stamp),
            default => $this->unsupported($driver),
        };

        if ($code === self::SUCCESS) {
            $this->pruneOld($dir);
        }

        return $code;
    }

    /** Days an older dump is kept. */
    public const KEEP_DAYS = 7;

    /**
     * Remove dumps older than {@see KEEP_DAYS}, always keeping the newest.
     *
     * The newest is the one an update's rollback reads, so it is kept however old it is.
     *
     * Only names this command writes, aged by the stamp in the name; files put here by hand
     * are left alone. Prints nothing: the update script takes the last output line as the path
     * of the dump just made.
     */
    private function pruneOld(string $dir): void
    {
        $dumps = [];

        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/\Apanel-(\d{8}-\d{6})\.(?:sqlite|sql)\z/', $name, $m) === 1 && is_file($dir.'/'.$name)) {
                $dumps[$name] = $m[1];
            }
        }

        // The stamp is the name's sort order, so this is newest first.
        krsort($dumps, SORT_STRING);
        array_shift($dumps);

        $cutoff = now()->subDays(self::KEEP_DAYS)->format('Ymd-His');

        foreach ($dumps as $name => $stamp) {
            if (strcmp($stamp, $cutoff) < 0) {
                @unlink($dir.'/'.$name);
            }
        }
    }

    /**
     * A sqlite database is one file, but it must not be copied with `cp`: a plain copy of a
     * file being written to can capture a torn page.
     */
    private function dumpSqlite(string $dir, string $stamp): int
    {
        $source = config('database.connections.'.config('database.default').'.database');

        if (! is_string($source) || ! is_file($source)) {
            $this->error('sqlite database file not found.');

            return self::FAILURE;
        }

        $target = $dir.'/panel-'.$stamp.'.sqlite';

        // VACUUM INTO takes a consistent snapshot of a live database.
        DB::statement('VACUUM INTO ?', [$target]);

        @chmod($target, 0640);
        $this->info($target);

        return self::SUCCESS;
    }

    /**
     * Credentials go in a 0600 defaults-file, never on the command line —
     * argv is world-readable through /proc.
     */
    private function dumpMysql(string $connection, string $dir, string $stamp): int
    {
        $config = config("database.connections.{$connection}");
        $target = $dir.'/panel-'.$stamp.'.sql';
        $defaults = $dir.'/.my-'.$stamp.'.cnf';

        file_put_contents($defaults, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n",
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['username'] ?? '',
            str_replace('"', '\\"', (string) ($config['password'] ?? '')),
        ));
        chmod($defaults, 0600);

        try {
            $result = Process::timeout(600)->run([
                'mysqldump',
                '--defaults-file='.$defaults,
                '--single-transaction',
                '--result-file='.$target,
                $config['database'],
            ]);
        } finally {
            @unlink($defaults);
        }

        if (! $result->successful()) {
            $this->error('mysqldump failed.');

            return self::FAILURE;
        }

        @chmod($target, 0640);
        $this->info($target);

        return self::SUCCESS;
    }

    /**
     * PostgreSQL.
     *
     * Credentials go in a 0600 `PGPASSFILE`, like {@see PgsqlEngine}: a pgpass file with group
     * or world access is ignored, so the mode decides whether authentication works. Never
     * `PGPASSWORD`, never on argv.
     *
     * No `--single-transaction`: `pg_dump` already runs in one repeatable-read snapshot.
     */
    private function dumpPostgres(string $connection, string $dir, string $stamp): int
    {
        $config = config("database.connections.{$connection}");
        $target = $dir.'/panel-'.$stamp.'.sql';
        $passFile = $dir.'/.pg-'.$stamp.'.pgpass';

        // Escape a literal colon or backslash, or it is read as a separator and shifts every
        // later field.
        $fields = array_map(
            fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value),
            [
                (string) ($config['host'] ?? '127.0.0.1'),
                (string) ($config['port'] ?? 5432),
                (string) ($config['database'] ?? '*'),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
            ],
        );

        file_put_contents($passFile, implode(':', $fields)."\n");
        chmod($passFile, 0600);

        try {
            $result = Process::timeout(600)->env(['PGPASSFILE' => $passFile])->run([
                'pg_dump',
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? 5432),
                '--username='.($config['username'] ?? ''),
                // Fail instead of blocking on a prompt nothing will answer.
                '--no-password',
                '--file='.$target,
                (string) $config['database'],
            ]);
        } finally {
            @unlink($passFile);
        }

        if (! $result->successful()) {
            $this->error('pg_dump failed.');

            return self::FAILURE;
        }

        @chmod($target, 0640);
        $this->info($target);

        return self::SUCCESS;
    }

    /**
     * Fail loudly: an update that silently skips its backup has no rollback.
     */
    private function unsupported(?string $driver): int
    {
        $this->error("Cannot back up a '{$driver}' database.");

        return self::FAILURE;
    }
}
