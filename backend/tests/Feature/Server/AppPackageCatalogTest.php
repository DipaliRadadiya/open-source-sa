<?php

use App\Models\AppPackageRelease;
use App\Services\Applications\SiteTypeManager;
use App\Services\Runtime\AppPackageCatalog;
use Illuminate\Support\Facades\Http;

/*
 * The drift this removes, from the day it was found:
 *
 * The n8n installer resolved `latest` while N8nSiteType declared 20.19–24,
 * numbers transcribed from the registry months earlier. n8n 1.x really did
 * declare `>=20.19 <= 24.x`; 2.x declares `>=24.0.0` and no ceiling. Two facts
 * that must agree, maintained by hand in different files, with nothing to
 * notice when they stopped — and a failure that lands later and elsewhere, as
 * a site that installs cleanly and then will not start.
 */

it('reads the range off the release rather than a literal', function () {
    Http::fake([
        'registry.npmjs.org/n8n/latest' => Http::response([
            'version' => '2.39.7',
            'engines' => ['node' => '>=24.0.0'],
        ]),
    ]);

    app(AppPackageCatalog::class)->refresh(['n8n' => 'latest']);

    expect(AppPackageRelease::find('n8n')->only(['version', 'node_range']))
        ->toBe(['version' => '2.39.7', 'node_range' => '>=24.0.0'])
        ->and(app(AppPackageCatalog::class)->nodeRange('n8n'))
        ->toBe(['min' => '24.0.0', 'max' => null]);
});

it('asks the registry in a way the version endpoint accepts', function () {
    /*
     * Found on a real box after this shipped green everywhere else.
     *
     * `application/vnd.npm.install-v1+json` selects the abbreviated packument
     * and is only defined for the package endpoint. On the single-version
     * endpoint the registry may answer 406 Not Acceptable, and whether it does
     * depends on which CDN edge takes the request — so the header gave 200 on
     * one machine and 406 on another, which is a refresh that works in
     * development and silently stores nothing in production.
     */
    Http::fake([
        'registry.npmjs.org/*' => Http::response([
            'version' => '2.39.7',
            'engines' => ['node' => '>=24.0.0'],
        ]),
    ]);

    app(AppPackageCatalog::class)->refresh(['n8n' => 'latest']);

    Http::assertSent(fn ($request) => ! collect($request->headers()['Accept'] ?? [])
        ->contains(fn (string $value) => str_contains($value, 'vnd.npm.install-v1+json')));
});

it('keeps yesterdays range when the registry cannot be reached', function () {
    AppPackageRelease::create(['package' => 'n8n', 'version' => '2.39.7', 'node_range' => '>=24.0.0']);

    Http::fake(['registry.npmjs.org/*' => Http::response('', 503)]);

    app(AppPackageCatalog::class)->refresh(['n8n' => 'latest']);

    // Blanking it would widen the picker back to versions the application
    // refuses — a network blip must not do that.
    expect(AppPackageRelease::find('n8n')->node_range)->toBe('>=24.0.0');
});

it('falls back to the range the site type declares when nothing is stored', function () {
    expect(AppPackageRelease::query()->count())->toBe(0);

    $range = app(SiteTypeManager::class)->find('n8n')->supportedNodeRange();

    // A server with no egress still gets a working form.
    expect($range)->toBe(['min' => '24', 'max' => null]);
});

it('lets the stored range override the declared one', function () {
    // The point of the whole exercise: when n8n's next major moves its floor,
    // the picker moves without anybody editing a site type.
    AppPackageRelease::create(['package' => 'n8n', 'version' => '3.0.0', 'node_range' => '>=26.0.0']);

    expect(app(SiteTypeManager::class)->find('n8n')->supportedNodeRange())
        ->toBe(['min' => '26.0.0', 'max' => null]);
});

describe('parsing engines.node', function () {
    $parse = fn (string $range) => app(AppPackageCatalog::class)->parse($range);

    it('reads a floor with no ceiling', function () use ($parse) {
        expect($parse('>=24.0.0'))->toBe(['min' => '24.0.0', 'max' => null]);
    });

    it('reads the closed range n8n 1.x actually published', function () use ($parse) {
        // Verbatim from the registry, spaces and `.x` included.
        expect($parse('>=20.19 <= 24.x'))->toBe(['min' => '20.19', 'max' => '24']);
    });

    it('steps an exclusive ceiling down a major', function () use ($parse) {
        // `<25` excludes 25 entirely, so the highest allowed major is 24.
        expect($parse('>=22 <25'))->toBe(['min' => '22', 'max' => '24']);
    });

    it('refuses a union rather than inventing one window', function () use ($parse) {
        // npm 12 publishes `^22.22.2 || ^24.15.0 || >=26.0.0`. No single
        // min/max describes that honestly, and a guess would silently hide a
        // version somebody needs.
        expect($parse('^22.22.2 || ^24.15.0 || >=26.0.0'))->toBeNull();
    });

    it('refuses what it cannot read instead of guessing', function () use ($parse) {
        // A wrong bound never surfaces as an error — only as a version quietly
        // missing from a dropdown — so "not sure" has to stay expressible.
        expect($parse(''))->toBeNull()
            ->and($parse('*'))->toBeNull()
            ->and($parse('^24.0.0'))->toBeNull();
    });
});
