<?php

use App\Models\NpmRelease;
use App\Services\Panel\UpdateScript;
use App\Services\Runtime\NpmCatalog;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['server.runtimes.npm.registry_url' => 'https://registry.test/npm']);
});

/**
 * A registry packument in the shape the abbreviated document has, with the
 * real `engines.node` ranges npm publishes — the whole point of the catalog is
 * that those ranges exclude Node versions the panel still installs.
 *
 * Named for this file: Pest helpers share one global namespace across the
 * suite, and a second `packument()` somewhere else would take the run down.
 */
function npmCatalogPackument(): array
{
    return ['versions' => [
        '9.8.1' => ['engines' => ['node' => '^14.17.0 || ^16.13.0 || >=18.0.0']],
        '10.2.4' => ['engines' => ['node' => '^18.17.0 || >=20.5.0']],
        '10.9.9' => ['engines' => ['node' => '^18.17.0 || >=20.5.0']],
        '11.19.1' => ['engines' => ['node' => '^20.17.0 || >=22.9.0']],
        '12.0.2' => ['engines' => ['node' => '^22.22.2 || ^24.15.0 || >=26.0.0']],
    ]];
}

it('keeps the newest release of each npm major', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);

    expect(app(NpmCatalog::class)->refresh())->toBe(4);

    expect(NpmRelease::query()->pluck('version', 'major')->all())->toBe([
        '9' => '9.8.1',
        // Not 10.2.4: within a major only the newest is worth offering.
        '10' => '10.9.9',
        '11' => '11.19.1',
        '12' => '12.0.2',
    ]);
});

it('answers with the newest npm each node version can actually run', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);
    app(NpmCatalog::class)->refresh();

    $catalog = app(NpmCatalog::class);

    // The reason this class exists. npm 12 needs Node ^22.22.2, so on 24 it
    // is the answer and on 20 it is not — publishing the registry's `latest`
    // on every row would leave the button lit forever on Node 18 and 20.
    expect($catalog->latestFor('24.19.0'))->toBe('12.0.2')
        ->and($catalog->latestFor('22.9.0'))->toBe('11.19.1')
        ->and($catalog->latestFor('20.17.0'))->toBe('11.19.1')
        ->and($catalog->latestFor('18.20.4'))->toBe('10.9.9');
});

it('reads the whole range, not just the major', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);
    app(NpmCatalog::class)->refresh();

    $catalog = app(NpmCatalog::class);

    // Both of these are Node 20, and they get different answers: npm 11 needs
    // `^20.17.0`, so 20.11 is a Node 20 that cannot run the newest npm 11.
    // Keying the catalog on majors — the obvious shortcut — gets this wrong
    // and offers an npm that will not start.
    expect($catalog->latestFor('20.17.0'))->toBe('11.19.1')
        ->and($catalog->latestFor('20.11.0'))->toBe('10.9.9');
});

it('is conservative about a node version that falls short of the newest range', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);
    app(NpmCatalog::class)->refresh();

    // 22.14 satisfies npm 11's `>=22.9.0` but not npm 12's `^22.22.2`. Under-
    // offering by a major beats offering an npm that cannot start.
    expect(app(NpmCatalog::class)->latestFor('22.14.0'))->toBe('11.19.1');
});

it('says nothing rather than guessing when the catalog is empty', function () {
    // A box with no egress, or one that has not run the refresh yet. Null is
    // the frontend's signal to hide the comparison entirely.
    expect(app(NpmCatalog::class)->latestFor('20.11.0'))->toBeNull()
        ->and(app(NpmCatalog::class)->updateAvailable('20.11.0', '10.2.4'))->toBeFalse();
});

it('compares versions as semver, not as strings', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);
    app(NpmCatalog::class)->refresh();

    $catalog = app(NpmCatalog::class);

    // '9.8.1' string-compares as greater than '10.9.9'. A client doing this
    // itself would report the newest npm on Node 18 as an available update
    // and the older one as current — backwards, both ways.
    expect($catalog->updateAvailable('18.20.4', '9.8.1'))->toBeTrue()
        ->and($catalog->updateAvailable('18.20.4', '10.9.9'))->toBeFalse();
});

it('keeps yesterday answer when the registry cannot be reached', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);
    app(NpmCatalog::class)->refresh();

    Http::fake(['registry.test/*' => Http::response('', 503)]);

    expect(app(NpmCatalog::class)->refresh())->toBe(4);

    // A network blip must not blank a comparison that was correct yesterday.
    expect(app(NpmCatalog::class)->latestFor('20.17.0'))->toBe('11.19.1');
});

it('never offers a pre-release', function () {
    Http::fake(['registry.test/*' => Http::response(['versions' => [
        '11.19.1' => ['engines' => ['node' => '>=20.17.0']],
        '12.0.0-pre.1' => ['engines' => ['node' => '>=20.17.0']],
    ]])]);

    app(NpmCatalog::class)->refresh();

    expect(app(NpmCatalog::class)->latestFor('22.9.0'))->toBe('11.19.1');
});

it('asks the registry for the abbreviated document', function () {
    Http::fake(['registry.test/*' => Http::response(npmCatalogPackument())]);

    app(NpmCatalog::class)->refresh();

    // Without the header the registry returns every release's full metadata,
    // README included — five times the bytes for two fields.
    Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/vnd.npm.install-v1+json'));
});

it('is primed by both the installer and the panel update script', function () {
    // `npm_latest` is read from this table, and an empty table is reported as
    // null — which the panel correctly reads as "we do not know" and so keeps
    // the Update button offered on an npm that is already current. This
    // command is the only thing that fills it, and it was scheduled daily and
    // called from nowhere else, so every fresh install and every update spent
    // up to a day in exactly that state.
    expect(file_get_contents(base_path('../install.sh')))->toContain('artisan runtimes:refresh-npm')
        ->and(UpdateScript::STEPS)->toContain('refresh_npm_catalogue');

    // After `migrate`, because the table it writes to has to exist first.
    $steps = array_flip(UpdateScript::STEPS);
    expect($steps['refresh_npm_catalogue'])->toBeGreaterThan($steps['migrate']);
});

it('does not fail an install or an update when the registry is unreachable', function () {
    // The npm registry is a third party. A panel that refuses to finish
    // updating because someone else's API was down is worse than one whose
    // Update button is briefly over-eager, so both call sites guard the exit
    // code — and the command itself must not report failure either.
    Http::fake(fn () => Http::response('', 500));

    $this->artisan('runtimes:refresh-npm')->assertSuccessful();

    // And the guard is present at both call sites, so a future command that
    // *does* exit non-zero still cannot take the update down with it.
    expect(file_get_contents(base_path('app/Services/Panel/UpdateScript.php')))
        ->toContain('artisan runtimes:refresh-npm || echo');
});

it('keeps what it already had when a refresh fails', function () {
    // The failure that matters most: a comparison that was correct yesterday
    // must not be blanked into "we do not know" by one bad night.
    NpmRelease::create(['major' => '11', 'version' => '11.19.1', 'node_range' => '^20.17.0 || >=22.9.0']);

    Http::fake(fn () => Http::response('', 500));

    $this->artisan('runtimes:refresh-npm')->assertSuccessful();

    expect(app(NpmCatalog::class)->latestFor('20.17.0'))->toBe('11.19.1');
});
