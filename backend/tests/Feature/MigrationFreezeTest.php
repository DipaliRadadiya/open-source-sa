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
 *
 * The squash, 2026-10-23. The 104 migrations shipped up to v1.0.19 were
 * replaced by one migration per table (`2026_10_24_0000NN_create_*`), each
 * creating its table in its final shape. That deletes shipped migrations, the
 * very thing this file forbids, and it was done on purpose: Dipali was
 * rebuilding every install, test servers included, from scratch at the time.
 * There is no upgrade path across the squash. A panel installed before it is
 * reinstalled, never upgraded: its `migrations` table records none of the
 * new files, so `migrate` would try to create every table it already has.
 *
 * The rule therefore holds again from the baseline on, with three checks:
 *
 *  - A released migration may be missing from the tree only when it sorts
 *    before the baseline, which is what "removed by the squash" means. One
 *    deleted after the squash fails.
 *  - Nothing in the tree may sort before the baseline. Migrations run in
 *    filename order, so a `2026_10_23_*_add_x` from a branch cut before the
 *    squash would run before the table it alters exists.
 *  - The baseline files are pinned by hash (tests/Fixtures/migration-
 *    baseline.sha256) from the moment they land, not only from the first
 *    release that contains them: the rebuilt servers run them straight from
 *    the branch, so for them an edit is already an edit to a shipped file.
 *    The release comparison would not see it until the next tag.
 */

/** Every migration file sorts after this; the squashed set sorted before it. */
function migrationSquashBaseline(): string
{
    return '2026_10_24_000000';
}

function latestReleaseTag(): ?string
{
    $result = Process::path(base_path('..'))->run(['git', 'tag', '--sort=-v:refname']);

    if ($result->failed()) {
        return null;
    }

    foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $tag) {
        $tag = trim($tag);

        // Not one of this panel's releases (those are v1.x), yet it sorts
        // above all of them, so comparing against it would check the tree
        // against an older commit than the real latest release.
        if ($tag === 'v7.0.18') {
            continue;
        }

        if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1) {
            return $tag;
        }
    }

    return null;
}

/** @return list<string>|null the migration filenames in `$tag`, or null when git cannot say */
function releasedMigrations(string $tag): ?array
{
    $result = Process::path(base_path('..'))->run(['git', 'ls-tree', '--name-only', $tag, 'backend/database/migrations/']);

    if ($result->failed()) {
        return null;
    }

    return array_values(array_filter(array_map(
        fn (string $line): string => basename(trim($line)),
        preg_split('/\r?\n/', trim($result->output())) ?: [],
    ), fn (string $name): bool => str_ends_with($name, '.php')));
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

it('never deletes a released migration, except the set the squash replaced', function () {
    $tag = latestReleaseTag();
    $released = $tag === null ? null : releasedMigrations($tag);

    if ($released === null) {
        $this->markTestSkipped('no release tag available to compare against');
    }

    $deleted = array_values(array_filter(
        $released,
        fn (string $name): bool => strcmp($name, migrationSquashBaseline()) > 0
            && ! is_file(database_path('migrations/'.$name)),
    ));

    expect($deleted)->toBe([], implode("\n", array_merge(
        ['These migrations shipped in '.$tag.' and have been deleted since:'],
        array_map(fn (string $name): string => '  - '.$name, $deleted),
        ['', 'Panels upgraded from '.$tag.' ran them; a fresh install now would not.'],
    )));
});

it('has no migration that would run before the tables of the baseline exist', function () {
    $early = collect(glob(database_path('migrations/*.php')))
        ->map(fn (string $path): string => basename($path))
        ->filter(fn (string $name): bool => strcmp($name, migrationSquashBaseline()) < 0)
        ->values()
        ->all();

    expect($early)->toBe([], 'Rename these after '.migrationSquashBaseline().' so they run after the baseline: '.implode(', ', $early));
});

it('keeps every baseline migration exactly as it was squashed', function () {
    $pinned = collect(file(base_path('tests/Fixtures/migration-baseline.sha256'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->mapWithKeys(function (string $line): array {
            [$hash, $name] = preg_split('/\s+/', trim($line), 2);

            return [$name => $hash];
        });

    expect($pinned)->toHaveCount(60);

    $changed = $pinned
        ->filter(fn (string $hash, string $name): bool => ! is_file(database_path('migrations/'.$name))
            || hash_file('sha256', database_path('migrations/'.$name)) !== $hash)
        ->keys()
        ->all();

    expect($changed)->toBe([], implode("\n", array_merge(
        ['These baseline migrations were edited or removed after the squash:'],
        array_map(fn (string $name): string => '  - '.$name, $changed),
        ['', 'Every panel built since the squash has already run them. Add a new migration instead.'],
    )));
});
