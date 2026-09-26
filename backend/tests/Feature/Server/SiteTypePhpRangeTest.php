<?php

use App\Rules\SupportedPhpVersion;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Support\Facades\Validator;

/*
| The PHP range each site type publishes.
|
| 🔴 Until 2026-09-18 not one of these had a test, which is why three of them
| were wrong or missing without anything going red.
|
| Every number below was measured on that date from the artifact the panel
| actually downloads — not from the vendor's newest documentation, which
| describes a release we may not install. That distinction is the whole reason
| this file exists:
|
|   - PrestaShop's stable feed serves 8.2.1; there is no 9.x in it at all.
|   - Moodle is pinned to `stable500`, so 5.0, not whatever is newest.
|
| A proposal to "modernise" both to their latest upstream requirements would
| have broken installs in the first case and refused a supported version in the
| second. If a range here looks stale, re-measure the release before changing
| it — `git log` this file for how to.
*/

dataset('published ranges', [
    // type         min       max
    'wordpress' => ['wordpress', '7.4', null],
    'joomla' => ['joomla', '8.3', null],
    'moodle' => ['moodle', '8.2', null],
    'mautic' => ['mautic', '8.2', '8.5'],
    'akaunting' => ['akaunting', '8.1', null],
    'nextcloud' => ['nextcloud', '8.3', '8.5'],
    'phpmyadmin' => ['phpmyadmin', '7.2', null],
    'craftcms' => ['craftcms', '8.2', null],
    'statamic' => ['statamic', '8.3', null],
]);

it('publishes the range its installed release declares', function (string $type, ?string $min, ?string $max) {
    $range = app(SiteTypeManager::class)->find($type)?->supportedPhpRange();

    expect($range)->not->toBeNull("{$type} publishes no PHP range")
        ->and($range['min'] ?? null)->toBe($min)
        ->and($range['max'] ?? null)->toBe($max);
})->with('published ranges');

it('gives PrestaShop the union of the two branches it can install', function () {
    // Measured from each release's install/install_version.php, 2026-09-26:
    // 9.1.5 runs on 8.1 – 8.5, 8.2.8 on 7.2.5 – 8.1. The installer picks per
    // site PHP (PrestaShopInstallTest), which is the only reason the old 8.1
    // ceiling may go: on 2026-09-08 a shop on 8.5 died in
    // ProxyCacheWarmer->warmUp() because it was given PrestaShop 8. 8.5 now
    // gets 9. Raising this past 8.5 needs a release that says so.
    $range = app(SiteTypeManager::class)->find('prestashop')?->supportedPhpRange();

    expect($range['min'])->toBe('7.2')
        ->and($range['max'])->toBe('8.5');
});

it('leaves a blank PHP site with no opinion at all', function () {
    // A range is for an application whose requirements we know. A custom site
    // runs the user's own code, and refusing a version on its behalf would be
    // inventing a constraint nobody stated.
    expect(app(SiteTypeManager::class)->find('php')?->supportedPhpRange())->toBeNull();
});

it('actually refuses a version outside the range, rather than only publishing it', function () {
    // The data is only worth correcting if something enforces it. This is the
    // rule SavePhpSettingsRequest and StoreApplicationRequest both build from
    // supportedPhpRange().
    $rule = new SupportedPhpVersion('8.3', '8.5', 'Nextcloud');

    $fails = fn (string $version) => Validator::make(
        ['php_version' => $version],
        ['php_version' => [$rule]],
    )->fails();

    expect($fails('8.2'))->toBeTrue()
        ->and($fails('8.6'))->toBeTrue()
        ->and($fails('8.3'))->toBeFalse()
        ->and($fails('8.5'))->toBeFalse();
});

it('accepts anything above the floor when a type declares no ceiling', function () {
    $rule = new SupportedPhpVersion('7.2', null, 'phpMyAdmin');

    $fails = fn (string $version) => Validator::make(
        ['php_version' => $version],
        ['php_version' => [$rule]],
    )->fails();

    // The case that motivated dropping a proposed 8.3 ceiling: phpMyAdmin
    // declares `^7.2.5 || ^8.0` and states minimums only, so 8.4 and 8.5 are
    // versions it supports and we were about to refuse.
    expect($fails('7.1'))->toBeTrue()
        ->and($fails('7.2'))->toBeFalse()
        ->and($fails('8.4'))->toBeFalse()
        ->and($fails('8.5'))->toBeFalse();
});
