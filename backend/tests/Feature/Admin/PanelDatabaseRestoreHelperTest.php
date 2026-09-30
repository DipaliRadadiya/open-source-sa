<?php

use Illuminate\Support\Facades\File;

/*
 * The helper the update rollback runs to put the panel's SQLite database back.
 * Run for real, against real SQLite files: it is the one step of a failed
 * update that decides whether the panel can ever update again.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/restore-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    $this->live = $this->dir.'/database.sqlite';
    $this->backup = $this->dir.'/panel-backup.sqlite';
    $this->failed = $this->dir.'/panel-backup-failed.sqlite';

    $db = new PDO('sqlite:'.$this->live);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
    $db->exec("INSERT INTO migrations (migration, batch) VALUES ('0001_create_users', 1)");
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $db->exec("INSERT INTO users (name) VALUES ('alice')");
    $db->exec('VACUUM INTO '.$db->quote($this->backup));
    $db = null;
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function runRestore(array $extra = []): array
{
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', [
        PHP_BINARY, resource_path('panel-update/restore-database.php'),
        test()->live, test()->backup, test()->failed, ...$extra,
    ])).' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

function liveTables(): array
{
    return (new PDO('sqlite:'.test()->live))
        ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
}

it('leaves the database alone when the update did not change it', function () {
    $before = md5_file($this->live);

    [$code, $out] = runRestore();

    expect($code)->toBe(0)
        ->and($out)->toBe('unchanged')
        ->and(md5_file($this->live))->toBe($before)
        ->and(file_exists($this->failed))->toBeFalse();
});

it('undoes a migration that failed halfway, table and all', function () {
    // What the test server showed: the table is created, the migration then
    // throws, and no row reaches `migrations`.
    (new PDO('sqlite:'.$this->live))->exec('CREATE TABLE qa_rollback_probe (id INTEGER PRIMARY KEY)');

    [$code, $out] = runRestore();

    expect($code)->toBe(10)
        ->and($out)->toBe('restored')
        ->and(liveTables())->not->toContain('qa_rollback_probe')
        ->and(liveTables())->toContain('users');
});

it('keeps the failed state rather than deleting it', function () {
    (new PDO('sqlite:'.$this->live))->exec('CREATE TABLE qa_rollback_probe (id INTEGER PRIMARY KEY)');

    runRestore();

    $failed = (new PDO('sqlite:'.$this->failed))
        ->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

    expect($failed)->toContain('qa_rollback_probe')
        ->and(file_exists($this->backup))->toBeTrue();
});

it('undoes a migration that was recorded', function () {
    (new PDO('sqlite:'.$this->live))->exec("INSERT INTO migrations (migration, batch) VALUES ('0002_something', 2)");

    [$code] = runRestore();

    $migrations = (new PDO('sqlite:'.$this->live))->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

    expect($code)->toBe(10)->and($migrations)->toBe(['0001_create_users']);
});

it('restores on --force even when only data changed', function () {
    // A failure inside the migrate step may have changed rows only.
    (new PDO('sqlite:'.$this->live))->exec("UPDATE users SET name = 'half-migrated'");

    expect(runRestore()[0])->toBe(0);

    [$code] = runRestore(['--force']);
    $name = (new PDO('sqlite:'.$this->live))->query('SELECT name FROM users')->fetchColumn();

    expect($code)->toBe(10)->and($name)->toBe('alice');
});

it('takes pending WAL pages into the kept copy, and does not replay them onto the backup', function () {
    // A connection that stays open keeps its writes in the -wal file.
    $open = new PDO('sqlite:'.$this->live);
    $open->exec('PRAGMA wal_autocheckpoint = 0');
    $open->exec('CREATE TABLE qa_rollback_probe (id INTEGER PRIMARY KEY)');
    $open->exec('INSERT INTO qa_rollback_probe DEFAULT VALUES');

    expect(filesize($this->live.'-wal'))->toBeGreaterThan(0);

    [$code] = runRestore();
    $open = null;

    $kept = (new PDO('sqlite:'.$this->failed))->query('SELECT count(*) FROM qa_rollback_probe')->fetchColumn();

    expect($code)->toBe(10)
        ->and((int) $kept)->toBe(1)
        ->and(liveTables())->not->toContain('qa_rollback_probe');
});

it('does nothing, and says so, when there is no backup to restore from', function () {
    (new PDO('sqlite:'.$this->live))->exec('CREATE TABLE qa_rollback_probe (id INTEGER PRIMARY KEY)');
    unlink($this->backup);

    [$code] = runRestore();

    expect($code)->toBe(3)
        ->and(liveTables())->toContain('qa_rollback_probe');
});
