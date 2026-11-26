<?php

use Illuminate\Support\Facades\Process;

/*
 * The panel's backup of its own database, before an update migrates it.
 *
 * `UpdateScript` runs this step under `set -e` with a rollback trap, so a driver with no arm
 * here makes every update fail and roll back.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/panel-backup-'.bin2hex(random_bytes(4));
    $this->state = new stdClass;
    $this->state->seen = [];
});

afterEach(function () {
    if (is_dir($this->dir)) {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        array_map('unlink', glob($this->dir.'/.*.pgpass') ?: []);
        @rmdir($this->dir);
    }
});

/**
 * Describe the default connection as PostgreSQL, rather than repointing `database.default`.
 *
 * Switching the default mid-test makes `RefreshDatabase` roll back on a connection it never opened.
 */
function describedAsPostgres(): void
{
    $connection = config('database.default');

    config([
        "database.connections.{$connection}.driver" => 'pgsql',
        "database.connections.{$connection}.host" => '127.0.0.1',
        "database.connections.{$connection}.port" => 5432,
        "database.connections.{$connection}.database" => 'panel',
        "database.connections.{$connection}.username" => 'panel_admin',
        "database.connections.{$connection}.password" => 'pa:ss\\word',
    ]);
}

it('backs up a PostgreSQL panel instead of failing the whole update', function () {
    describedAsPostgres();

    Process::fake(function ($process) {
        test()->state->seen[] = $process;

        return Process::result(exitCode: 0);
    });

    $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertSuccessful();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'pg_dump');
});

it('keeps the password out of argv and in a file only its owner can read', function () {
    describedAsPostgres();

    $mode = null;
    $passFileContents = null;

    Process::fake(function ($process) use (&$mode, &$passFileContents) {
        // Read while the command is "running": the file is unlinked in a `finally` on return.
        $file = $process->environment['PGPASSFILE'] ?? null;

        if (is_string($file) && is_file($file)) {
            $mode = substr(sprintf('%o', fileperms($file)), -4);
            $passFileContents = file_get_contents($file);
        }

        return Process::result(exitCode: 0);
    });

    $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertSuccessful();

    // PostgreSQL ignores a pgpass file with group or world access, so 0600 is required.
    expect($mode)->toBe('0600');

    // Colon and backslash in the password are escaped, or every field after them shifts.
    expect($passFileContents)->toBe("127.0.0.1:5432:panel:panel_admin:pa\\:ss\\\\word\n");

    // argv is world-readable through /proc, so the password must not be in it.
    foreach (test()->state->seen as $process) {
        expect(implode(' ', (array) $process->command))->not->toContain('pa:ss');
    }
    Process::assertRan(fn ($p) => ! str_contains(implode(' ', (array) $p->command), 'pa:ss'));
});

it('reports a failed dump as a failure rather than a silent skip', function () {
    describedAsPostgres();

    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'pg_dump: error: connection failed'));

    $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertFailed();
});

it('still refuses a driver it genuinely cannot back up', function () {
    // An update must not proceed believing it has a rollback it does not have.
    config(['database.connections.'.config('database.default').'.driver' => 'sqlsrv']);

    Process::fake();

    $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertFailed();
    Process::assertNothingRan();
});

it('has an arm for every driver the panel offers', function () {
    // Anything `config/database.php` offers must be backed up; `sqlsrv` is Laravel scaffolding
    // the panel does not document or install.
    $offered = array_diff(
        array_keys((array) config('database.connections')),
        ['sqlsrv'],
    );

    $source = (string) file_get_contents(app_path('Console/Commands/BackupPanelDatabase.php'));

    foreach ($offered as $connection) {
        $driver = (string) config("database.connections.{$connection}.driver");

        expect($source)->toContain("'{$driver}'");
    }
});

/*
 * One dump before every update. Dumps older than a week are removed; the newest stays however old,
 * because an update's rollback reads it.
 */
describe('removing old dumps', function () {
    /** pg_dump that really writes its file, so the dump just made is on disk. */
    function fakeWritingPgDump(): void
    {
        describedAsPostgres();

        Process::fake(function ($process) {
            foreach ((array) $process->command as $arg) {
                if (str_starts_with($arg, '--file=')) {
                    file_put_contents(substr($arg, 7), '-- dump');
                }
            }

            return Process::result(exitCode: 0);
        });
    }

    function dumpAged(string $dir, int $days, string $ext = 'sql'): string
    {
        $name = 'panel-'.now()->subDays($days)->format('Ymd-His').'.'.$ext;
        file_put_contents($dir.'/'.$name, 'old');

        return $name;
    }

    it('removes dumps older than a week and keeps the recent ones', function () {
        mkdir($this->dir, 0750, true);
        $old = dumpAged($this->dir, 10);
        $older = dumpAged($this->dir, 30, 'sqlite');
        $recent = dumpAged($this->dir, 2);
        fakeWritingPgDump();

        $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertSuccessful();

        $left = array_values(array_diff(scandir($this->dir), ['.', '..']));

        expect($left)->toContain($recent)
            ->not->toContain($old)
            ->not->toContain($older)
            ->and(count($left))->toBe(2);
    });

    it('keeps the newest dump however old it is', function () {
        mkdir($this->dir, 0750, true);
        $only = dumpAged($this->dir, 40);
        $older = dumpAged($this->dir, 50);
        describedAsPostgres();
        // A failed dump removes nothing: the old ones are all there is.
        Process::fake(fn () => Process::result(exitCode: 1));

        $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertFailed();

        expect(file_exists($this->dir.'/'.$only))->toBeTrue()
            ->and(file_exists($this->dir.'/'.$older))->toBeTrue();

        // And on success with no new file written, the newest present survives.
        Process::fake(fn () => Process::result(exitCode: 0));
        $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertSuccessful();

        expect(file_exists($this->dir.'/'.$only))->toBeTrue()
            ->and(file_exists($this->dir.'/'.$older))->toBeFalse();
    });

    it('leaves files it did not write alone', function () {
        mkdir($this->dir, 0750, true);
        $mine = dumpAged($this->dir, 20);
        foreach (['notes.sql', 'panel-before-migration.sql', 'panel-20200101-000000.sql.gz'] as $name) {
            file_put_contents($this->dir.'/'.$name, 'x');
            touch($this->dir.'/'.$name, now()->subDays(90)->getTimestamp());
        }
        fakeWritingPgDump();

        $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertSuccessful();

        expect(file_exists($this->dir.'/'.$mine))->toBeFalse()
            ->and(file_exists($this->dir.'/notes.sql'))->toBeTrue()
            ->and(file_exists($this->dir.'/panel-before-migration.sql'))->toBeTrue()
            ->and(file_exists($this->dir.'/panel-20200101-000000.sql.gz'))->toBeTrue();
    });

    it('still prints the path of the dump it made', function () {
        mkdir($this->dir, 0750, true);
        dumpAged($this->dir, 20);
        fakeWritingPgDump();

        $this->artisan('panel:backup-database', ['--path' => $this->dir])
            ->expectsOutputToContain($this->dir.'/panel-')
            ->assertSuccessful();
    });
});
