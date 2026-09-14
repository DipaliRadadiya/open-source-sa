<?php

use Illuminate\Support\Facades\Process;

/*
 * The panel's backup of its own database, before an update migrates it.
 *
 * `UpdateScript` runs this step unguarded under `set -e` with a rollback trap,
 * so a driver with no arm here does not quietly skip its backup — it makes
 * every update on that panel fail and roll back. That is what a PostgreSQL
 * panel did until 2026-09-14.
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
 * Describe the default connection as PostgreSQL, rather than repointing
 * `database.default` at the pgsql connection.
 *
 * The command reads the driver of whatever `database.default` names, so this
 * exercises the same branch — and switching the default mid-test makes
 * `RefreshDatabase` try to roll back a transaction on a connection it never
 * opened ("cannot start a transaction within a transaction", then a refused
 * connection to a PostgreSQL that is not there).
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
        // Read while the command is "running" — the file is unlinked in a
        // `finally` the moment it returns.
        $file = $process->environment['PGPASSFILE'] ?? null;

        if (is_string($file) && is_file($file)) {
            $mode = substr(sprintf('%o', fileperms($file)), -4);
            $passFileContents = file_get_contents($file);
        }

        return Process::result(exitCode: 0);
    });

    $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertSuccessful();

    // Measured behaviour this mirrors: a pgpass file with group or world access
    // is warned about and then ignored, so 0600 is the difference between
    // authenticating and not.
    expect($mode)->toBe('0600');

    // The colon and backslash in the password are escaped, or every field after
    // them shifts and the client authenticates as somebody else, or nobody.
    expect($passFileContents)->toBe("127.0.0.1:5432:panel:panel_admin:pa\\:ss\\\\word\n");

    // argv is world-readable through /proc for the life of the process.
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
    // The arm that stays: an update must not proceed believing it has a
    // rollback it does not have.
    config(['database.connections.'.config('database.default').'.driver' => 'sqlsrv']);

    Process::fake();

    $this->artisan('panel:backup-database', ['--path' => $this->dir])->assertFailed();
    Process::assertNothingRan();
});

it('has an arm for every driver the panel offers', function () {
    // The guard against this gap reappearing. `config/database.php` is what an
    // operator reads when choosing, so anything it offers has to be backed up
    // — and `sqlsrv` is Laravel's default scaffolding rather than something
    // this panel documents or installs.
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
