<?php

use App\Models\AppPackageRelease;
use App\Rules\SupportedNodeVersion;
use App\Services\Applications\SiteTypeManager;
use App\Services\Runtime\AppPackageCatalog;
use Illuminate\Support\Facades\Http;

/*
 * The Node range an app supports is read from the registry, not hand-copied into the site type.
 * A stale copy fails later and elsewhere: a site that installs cleanly and then will not start.
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
     * `application/vnd.npm.install-v1+json` is only defined for the package endpoint. On the
     * single-version endpoint the registry may answer 406, depending on the CDN edge.
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

    // Blanking it would widen the picker to versions the application refuses.
    expect(AppPackageRelease::find('n8n')->node_range)->toBe('>=24.0.0');
});

it('falls back to the range the site type declares when nothing is stored', function () {
    expect(AppPackageRelease::query()->count())->toBe(0);

    $range = app(SiteTypeManager::class)->find('n8n')->supportedNodeRange();

    // A server with no egress still gets a working form.
    expect($range)->toBe(['min' => '24', 'max' => null]);
});

it('lets the stored range override the declared one', function () {
    // When n8n's next major moves its floor, the picker follows without editing a site type.
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
        // npm 12 publishes `^22.22.2 || ^24.15.0 || >=26.0.0`: no single min/max describes that.
        expect($parse('^22.22.2 || ^24.15.0 || >=26.0.0'))->toBeNull();
    });

    it('refuses what it cannot read instead of guessing', function () use ($parse) {
        // A wrong bound only shows as a version missing from a dropdown, so "not sure" must stay
        // expressible.
        expect($parse(''))->toBeNull()
            ->and($parse('*'))->toBeNull()
            ->and($parse('^24.0.0'))->toBeNull();
    });
});

describe('Node-RED (bug #16)', function () {
    it('declares the floor of the major the installer pins, not "any Node"', function () {
        // The type declared no range. The installer pins major 4, whose
        // engines.node is ">=18.5"; Node-RED 5 would need 22.9.
        expect(config('server.installers.nodered.version'))->toBe('4', 'The Node-RED pin moved: update NodeRedSiteType::supportedNodeRange() to that major\'s engines.node.')
            ->and(app(SiteTypeManager::class)->find('nodered')->supportedNodeRange())
            ->toBe(['min' => '18.5', 'max' => null]);
    });

    it('refuses a Node below the floor and admits one above it', function () {
        expect(SupportedNodeVersion::admits('18.5', null, '18.4.0'))->toBeFalse()
            ->and(SupportedNodeVersion::admits('18.5', null, '18.20.4'))->toBeTrue()
            ->and(SupportedNodeVersion::admits('18.5', null, '24.1.0'))->toBeTrue();
    });
});
