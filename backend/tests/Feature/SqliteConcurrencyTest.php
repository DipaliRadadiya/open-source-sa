<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * The panel's default database is a single SQLite file, and every API request
 * writes to it — the rate limiter alone bumps a counter on each one. Under
 * SQLite's default rollback journal a writer locks the whole file, so two
 * concurrent requests produce "database is locked" and a 500. The frontend
 * fires several on every page load, which is how this reached production as
 * 2,154 logged errors.
 */
it('configures sqlite for concurrent access', function () {
    $sqlite = config('database.connections.sqlite');

    expect(strtoupper((string) $sqlite['journal_mode']))->toBe('WAL')
        // Safe pairing with WAL: can lose the last transactions to a power
        // cut, never corrupts the file.
        ->and(strtoupper((string) $sqlite['synchronous']))->toBe('NORMAL')
        // Meaningful only once WAL removes the deadlock case, where SQLite
        // returns BUSY immediately without consulting the timeout at all.
        ->and((int) $sqlite['busy_timeout'])->toBeGreaterThan(0);
});

it('actually opens a file database in WAL mode', function () {
    // The in-memory database used by the rest of the suite cannot report WAL,
    // so this checks a real file — which is what ships.
    $path = sys_get_temp_dir().'/sv-oss-wal-'.getmypid().'.sqlite';
    File::put($path, '');

    config(['database.connections.wal_probe' => array_merge(
        config('database.connections.sqlite'),
        ['database' => $path],
    )]);

    try {
        expect(DB::connection('wal_probe')->select('PRAGMA journal_mode')[0]->journal_mode)->toBe('wal')
            ->and((int) DB::connection('wal_probe')->select('PRAGMA busy_timeout')[0]->timeout)->toBeGreaterThan(0);
    } finally {
        DB::purge('wal_probe');
        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            File::delete($file);
        }
    }
});

it('writes from a second connection while the first is reading', function () {
    $path = sys_get_temp_dir().'/sv-oss-conc-'.getmypid().'.sqlite';
    File::put($path, '');

    foreach (['reader', 'writer'] as $name) {
        config(["database.connections.{$name}" => array_merge(
            config('database.connections.sqlite'),
            ['database' => $path],
        )]);
    }

    try {
        DB::connection('writer')->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, v TEXT)');
        DB::connection('writer')->table('items')->insert(['v' => 'a']);

        // Hold an open read on one connection — under the rollback journal
        // this is what blocks the writer and produces the 500.
        $cursor = DB::connection('reader')->cursor('SELECT * FROM items');
        $cursor->current();

        DB::connection('writer')->table('items')->insert(['v' => 'b']);

        expect(DB::connection('writer')->table('items')->count())->toBe(2);
    } finally {
        DB::purge('reader');
        DB::purge('writer');
        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            File::delete($file);
        }
    }
});
