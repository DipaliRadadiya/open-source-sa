<?php

use App\Services\Panel\PanelLayout;

/*
 * The panel is moving from one in-place checkout to release directories with a
 * `current` symlink. Both shapes exist in the field at once — every server
 * already running is the old one — so the layout is asked, never assumed.
 */

function layoutFor(string $basePath): PanelLayout
{
    return new PanelLayout($basePath);
}

it('finds the root from inside a release, which is the path production sees', function () {
    // The service is started through `<root>/current/backend`, but this is the
    // path the app reports: bootstrap/app.php derives base_path() from
    // `dirname(__DIR__)`, and PHP resolves symlinks in __DIR__, so `current`
    // has already been replaced by the release it points at.
    //
    // Matching on `current` therefore never fired in production. root()
    // answered `<root>/releases/<timestamp>`, isReleased() looked for a
    // releases/ directory inside a release and found none, and every migrated
    // server silently ran the legacy update — `git checkout` in a tree built
    // by `git archive`, which carries no .git.
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
    // The migration writes this into shared/.env. Inference is a fallback for
    // installs that predate it, not the answer — a panel whose code has been
    // moved cannot deduce where its own layout begins.
    config()->set('panel_update.root', '/srv/control-panel/');

    expect(layoutFor('/var/www/panel/releases/20260822-093000/backend')->root())
        ->toBe('/srv/control-panel')
        ->and(layoutFor('/var/www/panel/backend')->root())->toBe('/srv/control-panel');
});

it('treats the checkout itself as the root on a legacy install', function () {
    // No `current` in the path, so nothing has been migrated. Answering with a
    // release layout here would have the update build one beside a working
    // install and point services at neither.
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
    // The sharpest edge in the whole design: a release carrying its own .env
    // would generate an APP_KEY, and every encrypted column — storage secrets,
    // git tokens, database passwords — becomes unreadable.
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

    // git archive cannot carry it: backend/database/.gitignore excludes
    // *.sqlite*, so every release is built without the panel's own database.
    // Unshared, the panel would open a path that does not exist — and SQLite
    // creates a missing database rather than refusing, so the update would
    // migrate a fresh empty schema and report success.
    expect($map)->toHaveKey('backend/database/database.sqlite')
        ->and($map['backend/database/database.sqlite'])->toBe('database/database.sqlite');
});

it('shares no database file when the panel is on MySQL', function () {
    $default = config('database.default');

    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.driver', 'mysql');

    // linkShared() removes the target before linking, so an unconditional
    // entry would leave a dangling symlink at backend/database/database.sqlite
    // — precisely where anything opening it would create an empty database.
    $map = layoutFor('/var/www/panel/releases/20260822-093000/backend')->sharedMap();

    // Put it back before the test ends: RefreshDatabase rolls its transaction
    // back on the *default* connection during teardown, and leaving it pointed
    // at MySQL fails the test for a reason that has nothing to do with it.
    config()->set('database.default', $default);

    expect($map)->not->toHaveKey('backend/database/database.sqlite');
});
