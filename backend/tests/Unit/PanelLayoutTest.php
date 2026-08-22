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

it('finds the root above a release when running from current', function () {
    // `<root>/current/backend` is where the app lives once migrated. Its
    // repository is `<root>/current`; the root is one further up, and that is
    // where releases/ and shared/ are.
    $layout = layoutFor('/var/www/panel/current/backend');

    expect($layout->root())->toBe('/var/www/panel')
        ->and($layout->currentLink())->toBe('/var/www/panel/current')
        ->and($layout->releasesPath())->toBe('/var/www/panel/releases')
        ->and($layout->sharedPath())->toBe('/var/www/panel/shared');
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
