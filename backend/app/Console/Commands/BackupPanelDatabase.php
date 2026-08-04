<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Dump the panel's own database before an update migrates it.
 *
 * Called by the update script rather than implemented inside it, because the
 * panel's database can be sqlite, mysql or mariadb and only Laravel knows
 * which one this install actually uses. Encoding that choice into generated
 * bash would mean guessing at render time about state that can change.
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

        return match ($driver) {
            'sqlite' => $this->dumpSqlite($dir, $stamp),
            'mysql', 'mariadb' => $this->dumpMysql($connection, $dir, $stamp),
            default => $this->unsupported($driver),
        };
    }

    /**
     * A sqlite database is one file. Copying it is the whole backup — but it
     * must be copied through the sqlite backup API rather than `cp`, because
     * a plain copy of a file being written to can capture a torn page.
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
     * Fail loudly. An update that silently skips its backup is an update with
     * no rollback, and the operator would not find out until they needed it.
     */
    private function unsupported(?string $driver): int
    {
        $this->error("Cannot back up a '{$driver}' database.");

        return self::FAILURE;
    }
}
