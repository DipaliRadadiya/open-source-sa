<?php

use Illuminate\Support\Facades\Process;

/*
 * A migration that has shipped is frozen.
 *
 * Laravel records a migration by filename, so editing a shipped create migration in place
 * reaches fresh installs only; upgraded panels never see it. The suite builds its schema from
 * scratch and cannot notice, so this compares the tree with the latest release.
 *
 * Two squashes replaced shipped migrations on purpose (one per table, final shape). There is
 * no upgrade path across either: such panels are reinstalled, since their `migrations` table
 * records none of the new files. After launch a change is a new migration.
 *
 * Three checks hold from the baseline on:
 *
 *  - A released migration may be missing only when it sorts before the baseline.
 *  - Nothing in the tree may sort before the baseline: migrations run in filename order, so an
 *    older-dated `add_x` would run before the table it alters exists.
 *  - The baseline files are pinned by hash (tests/Fixtures/migration-baseline.sha256) from the
 *    moment they land, since rebuilt servers run them straight from the branch and the release
 *    comparison would not see an edit until the next tag.
 */

/** Every migration file sorts after this; the squashed set sorted before it. */
function migrationSquashBaseline(): string
{
    return '2026_10_24_000000';
}

/** The last release the second squash leaves without an upgrade path. */
function secondSquashThroughRelease(): string
{
    return 'v8.0.20';
}

/** Skip the release comparison for a release the second squash cannot upgrade. */
function skipIfBeforeSecondSquash(string $tag): void
{
    if (version_compare(ltrim($tag, 'v'), ltrim(secondSquashThroughRelease(), 'v'), '<=')) {
        test()->markTestSkipped("{$tag} cannot be upgraded across the 2026-11-25 squash; such panels are reinstalled");
    }
}

function latestReleaseTag(): ?string
{
    $result = Process::path(base_path('..'))->run(['git', 'tag', '--sort=-v:refname']);

    if ($result->failed()) {
        return null;
    }

    foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $tag) {
        $tag = trim($tag);

        // Not one of this panel's releases, yet it sorts above all of them.
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

    // Skipped, not passed: a check that succeeds when it cannot look reads as a guarantee.
    if ($tag === null) {
        $this->markTestSkipped('no release tag available to compare against');
    }

    skipIfBeforeSecondSquash($tag);

    $changed = [];

    foreach (glob(database_path('migrations/*.php')) as $path) {
        $name = basename($path);
        $tracked = 'backend/database/migrations/'.$name;

        $released = Process::path(base_path('..'))->run(['git', 'show', $tag.':'.$tracked]);

        // Absent from the release: a new migration, the right way to change a schema.
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

    skipIfBeforeSecondSquash($tag);

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

    // One per table.
    expect($pinned)->toHaveCount(61);

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
