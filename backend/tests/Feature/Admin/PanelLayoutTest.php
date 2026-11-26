<?php

use App\Services\Panel\PanelLayout;

/*
 * Panels run either from one in-place checkout or from release directories with a
 * `current` symlink, so the layout is asked, never assumed.
 */

function layoutFor(string $basePath): PanelLayout
{
    return new PanelLayout($basePath);
}

it('finds the root from inside a release, which is the path production sees', function () {
    // The service starts through `<root>/current/backend`, but base_path() comes from
    // `dirname(__DIR__)` and PHP resolves symlinks in __DIR__, so the app reports the release
    // path. Matching on `current` would never fire: a migrated server would run the legacy
    // update (`git checkout` in a `git archive` tree, which has no .git).
    $layout = layoutFor('/var/www/panel/releases/20260822-093000/backend');

    expect($layout->root())->toBe('/var/www/panel')
        ->and($layout->currentLink())->toBe('/var/www/panel/current')
        ->and($layout->releasesPath())->toBe('/var/www/panel/releases')
        ->and($layout->sharedPath())->toBe('/var/www/panel/shared');
});

it('still answers for a literal current path, which callers may construct', function () {
    $layout = layoutFor('/var/www/panel/current/backend');

    expect($layout->root())->toBe('/var/www/panel');
});

it('prefers the recorded root over anything it could infer', function () {
    // The migration writes this into shared/.env. Inference is only a fallback for older
    // installs: a panel whose code has been moved cannot deduce where its layout begins.
    config()->set('panel_update.root', '/srv/control-panel/');

    expect(layoutFor('/var/www/panel/releases/20260822-093000/backend')->root())
        ->toBe('/srv/control-panel')
        ->and(layoutFor('/var/www/panel/backend')->root())->toBe('/srv/control-panel');
});

it('treats the checkout itself as the root on a legacy install', function () {
    // No `current` in the path means nothing has been migrated: answering with a release
    // layout would build one beside a working install and point services at neither.
    $layout = layoutFor('/var/www/panel/backend');

    expect($layout->root())->toBe('/var/www/panel');
});

it('names releases so they sort in the order they were made', function () {
    $layout = layoutFor('/var/www/panel/current/backend');

    $earlier = $layout->newReleasePath('20260822-041500');
    $later = $layout->newReleasePath('20260822-093000');

    expect($later)->toBeGreaterThan($earlier)
        ->and($later)->toBe('/var/www/panel/releases/20260822-093000');
});

it('keeps the env file shared rather than inside a release', function () {
    // A release carrying its own .env would generate a new APP_KEY, making every encrypted
    // column (storage secrets, git tokens, database passwords) unreadable.
    $map = layoutFor('/var/www/panel/current/backend')->sharedMap();

    expect($map)->toHaveKey('backend/.env')
        ->and($map['backend/.env'])->toBe('.env')
        // Storage too: logs, sessions and queued state must outlive a release.
        ->and($map)->toHaveKey('backend/storage');
});

it('shares the panel database when it is a SQLite file', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.driver', 'sqlite');

    $map = layoutFor('/var/www/panel/releases/20260822-093000/backend')->sharedMap();

    // `git archive` cannot carry it (backend/database/.gitignore excludes *.sqlite*). Unshared,
    // SQLite would create a fresh empty database and the update would migrate it and report success.
    expect($map)->toHaveKey('backend/database/database.sqlite')
        ->and($map['backend/database/database.sqlite'])->toBe('database/database.sqlite');
});

it('shares no database file when the panel is on MySQL', function () {
    $default = config('database.default');

    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.driver', 'mysql');

    // linkShared() removes the target before linking, so an unconditional entry would leave
    // a dangling symlink where opening it creates an empty database.
    $map = layoutFor('/var/www/panel/releases/20260822-093000/backend')->sharedMap();

    // Restore before the test ends: RefreshDatabase rolls back on the *default* connection
    // during teardown.
    config()->set('database.default', $default);

    expect($map)->not->toHaveKey('backend/database/database.sqlite');
});
