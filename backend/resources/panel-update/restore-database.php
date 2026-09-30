<?php

/*
 * Put the panel's SQLite database back after a failed update — only if the
 * update changed it.
 *
 * Standalone on purpose: no Laravel, no autoloader. The update script writes a
 * copy of this file beside itself when the update starts and runs it during
 * the rollback, after the previous code is back. The previous code may be a
 * release that predates this file, and the new code may be the thing that
 * broke, so neither can be asked to do it.
 *
 * Usage: php restore-database.php <live.sqlite> <backup.sqlite> <failed-copy.sqlite> [--force]
 *
 * "Changed" is the schema (sqlite_master) or the list of applied migrations
 * differing from the backup taken before the update. A migration that fails
 * halfway leaves a table and no row in `migrations` (seen on the test server,
 * 2026-09-30), so the schema is compared, not only the list. `--force` is for
 * a failure inside the migrate step itself, which may have changed data only.
 *
 * Nothing is deleted: the live database is copied to <failed-copy> first.
 *
 * Exit codes: 0 unchanged (nothing done), 10 restored, 3 no usable backup,
 * 4 restore failed (live database left as it was).
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: restore-database.php <live> <backup> <failed-copy> [--force]\n");
    exit(2);
}

[, $live, $backup, $failedCopy] = $argv;
$force = in_array('--force', array_slice($argv, 4), true);

if (! is_file($backup) || ! is_readable($backup) || ! is_file($live)) {
    fwrite(STDERR, "no usable backup at {$backup}\n");
    exit(3);
}

$open = static function (string $path): PDO {
    return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};

$fingerprint = static function (PDO $db): array {
    $schema = $db->query("SELECT type || '|' || name || '|' || coalesce(sql, '') FROM sqlite_master ORDER BY type, name")
        ->fetchAll(PDO::FETCH_COLUMN);

    $hasMigrations = (bool) $db->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'")
        ->fetchColumn();

    $migrations = $hasMigrations
        ? $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)
        : [];

    return [$schema, $migrations];
};

try {
    $liveDb = $open($live);

    if (! $force && $fingerprint($liveDb) === $fingerprint($open($backup))) {
        echo "unchanged\n";
        exit(0);
    }

    // Everything the live database holds, WAL included, into one file that
    // is kept — the "never delete a database" promise.
    $liveDb->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $liveDb->exec('VACUUM INTO '.$liveDb->quote($failedCopy));
    $liveDb = null;
} catch (Throwable $e) {
    fwrite(STDERR, 'could not read the live database: '.$e->getMessage()."\n");
    exit(4);
}

// Beside the live file, then renamed over it: a half-copied database is worse
// than either of the two whole ones.
$temporary = $live.'.restore-tmp';

if (! @copy($backup, $temporary)) {
    fwrite(STDERR, "could not copy {$backup}\n");
    exit(4);
}

@chmod($temporary, fileperms($live) & 0777);

// The WAL and shared-memory files belong to the database being replaced. The
// TRUNCATE checkpoint above has already emptied the WAL, so this is a safety
// net rather than the fix: a WAL left with pages in it would be replayed onto
// the restored file by the next connection.
@unlink($live.'-wal');
@unlink($live.'-shm');

if (! @rename($temporary, $live)) {
    @unlink($temporary);
    fwrite(STDERR, "could not replace {$live}\n");
    exit(4);
}

echo "restored\n";
exit(10);
