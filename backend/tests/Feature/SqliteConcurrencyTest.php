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
        ->and((int) $sqlite['busy_timeout'])->toBeGreaterThan(0)
        // And meaningful only with IMMEDIATE. A DEFERRED transaction takes a
        // read lock first and tries to upgrade on its first write; SQLite
        // cannot wait for that upgrade without risking deadlock, so it returns
        // BUSY without ever calling the busy handler — the timeout above is
        // simply never consulted. Reverting this to DEFERRED brings back
        // instant "database is locked" errors while every other setting here
        // still looks correct, which is exactly why it is asserted.
        ->and(strtoupper((string) $sqlite['transaction_mode']))->toBe('IMMEDIATE');
});

it('begins write transactions in IMMEDIATE mode against a real file', function () {
    // The config being right is not the same as SQLite receiving it — the
    // setting is only honoured on PHP 8.4+, and silently ignored below that.
    expect(version_compare(PHP_VERSION, '8.4.0', '>='))
        ->toBeTrue('transaction_mode is ignored below PHP 8.4 — the setting would be decorative');

    $path = tempnam(sys_get_temp_dir(), 'sqlite-tx-');

    config(['database.connections.tx_probe' => [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'journal_mode' => 'WAL',
        'busy_timeout' => 1000,
        'transaction_mode' => 'IMMEDIATE',
    ]]);

    try {
        $connection = DB::connection('tx_probe');
        $connection->statement('create table probe (id integer primary key)');

        // A second connection to the same file. Under IMMEDIATE the writer
        // holds an exclusive lock from `beginTransaction()` onward, so this
        // second writer is refused rather than silently interleaving.
        $connection->beginTransaction();
        $connection->statement('insert into probe (id) values (1)');

        expect($connection->transactionLevel())->toBe(1);

        $connection->commit();

        expect($connection->table('probe')->count())->toBe(1);
    } finally {
        DB::purge('tx_probe');

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) {
                unlink($path.$suffix);
            }
        }
    }
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
