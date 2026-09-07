<?php

use Illuminate\Support\Facades\Process;

/*
 * A migration that has shipped is frozen.
 *
 * On 2026-09-01 the `slug` column was added to `workers` by editing
 * create_workers_table in place. Laravel records a migration by filename, so
 * every panel that had already run that file never saw the edit. Fresh
 * installs got the column; upgraded ones answered 500 on the first worker
 * anyone created — `table workers has no column named slug`.
 *
 * Nothing in the suite could catch it. Tests build the schema from scratch,
 * where an edited create migration is simply correct. The bug only exists on a
 * database built by the *previous* version of that file, and no test has one.
 *
 * So this checks the one thing that is visible from here: whether a migration
 * already inside a release differs from what that release shipped. Changing a
 * released migration is the whole failure mode, and it is invisible every other
 * way until somebody upgrades.
 */

function latestReleaseTag(): ?string
{
    $result = Process::path(base_path('..'))->run(['git', 'tag', '--sort=-v:refname']);

    if ($result->failed()) {
        return null;
    }

    foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $tag) {
        if (preg_match('/^v\d+\.\d+\.\d+$/', trim($tag)) === 1) {
            return trim($tag);
        }
    }

    return null;
}

it('never changes a migration that has already shipped in a release', function () {
    $tag = latestReleaseTag();

    // A shallow clone or a checkout with no tags cannot answer this. Skipped
    // rather than passed: a check that silently succeeds when it cannot look is
    // worse than no check, because it reads as a guarantee.
    if ($tag === null) {
        $this->markTestSkipped('no release tag available to compare against');
    }

    $changed = [];

    foreach (glob(database_path('migrations/*.php')) as $path) {
        $name = basename($path);
        $tracked = 'backend/database/migrations/'.$name;

        $released = Process::path(base_path('..'))->run(['git', 'show', $tag.':'.$tracked]);

        // Absent from the release: a new migration, which is exactly the right
        // way to change a shipped schema.
        if ($released->failed()) {
            continue;
        }

        if (rtrim($released->output(), "\n") !== rtrim((string) file_get_contents($path), "\n")) {
            $changed[] = $name;
        }
    }

    expect($changed)->toBe([], implode("\n", array_merge(
        ['These migrations shipped in '.$tag.' and have been edited since:'],
        array_map(fn (string $name): string => '  - '.$name, $changed),
        [
            '',
            'Panels upgraded from '.$tag.' have already recorded these files as run',
            'and will never run them again, so the edit reaches new installs only.',
            'Add a new migration that alters the table instead.',
        ],
    )));
});
