<?php

use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Setup\SetupCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * A Docker server hosts containers and nothing else.
 *
 * The bug that produced this: the setup page recommended installing MySQL on a
 * Docker box. `DatabaseComponent::recommended()` returned a hardcoded `true`
 * and nothing in the setup catalogue was stack-aware — but the deeper fault was
 * that `capabilities.php` answers **"is PHP installed"**, which is true on
 * every box because the panel is a Laravel application, while the catalogue was
 * reading it as **"may this box host PHP sites"**. The same question until this
 * stack existed; different questions now.
 *
 * Hence `serving_profiles`, recorded per stack and never overwritten by
 * detection.
 */

function recordStack(string $stack): void
{
    ServerCapability::query()->delete();
    app(ServerCapabilities::class)->recordStack($stack);
}

it('keeps php installed and php sites unhosted, which are different answers', function () {
    recordStack('docker');

    $capabilities = app(ServerCapabilities::class);

    // Honest: PHP really is on the box, because the panel needs it. Reporting
    // false here would be a lie that the PHP screen and the doctor would both
    // contradict.
    expect($capabilities->current()->can('php'))->toBeTrue()
        // And yet no PHP site may be built. This pair is the whole change.
        ->and($capabilities->hosts('php'))->toBeFalse()
        ->and($capabilities->hosts('docker'))->toBeTrue();
});

it('records the right profiles for every stack', function () {
    foreach ([
        'lemp' => ['php', 'static'],
        'lamp' => ['php', 'static'],
        'ols' => ['php', 'static'],
        'mern' => ['node', 'static'],
        'docker' => ['docker'],
    ] as $stack => $expected) {
        recordStack($stack);

        expect(app(ServerCapabilities::class)->servingProfiles())
            ->toEqualCanonicalizing($expected, "stack {$stack}");
    }
});

it('does not lose the decision when a runtime is installed', function () {
    // `refresh()` is called whenever the panel installs or removes a runtime,
    // and it used to write `detectRuntimes()` in place of the whole array —
    // wiping `serving_profiles`, so a Docker box that installed anything
    // quietly started offering WordPress again. A recorded decision erased by
    // an unrelated observation.
    recordStack('docker');

    app(ServerCapabilities::class)->refresh();

    expect(app(ServerCapabilities::class)->hosts('php'))->toBeFalse()
        ->and(app(ServerCapabilities::class)->hosts('docker'))->toBeTrue();
});

it('is permissive about a server nobody recorded', function () {
    // A box migrated in from another panel has `stack = null` and is certainly
    // hosting something. Answering "hosts nothing" would empty the catalogue
    // on a working server.
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => null,
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'detected',
    ]);

    expect(app(ServerCapabilities::class)->hosts('php'))->toBeTrue()
        ->and(app(ServerCapabilities::class)->hosts('node'))->toBeTrue();
});

it('leaves types this stack will never host out of the grid', function () {
    // Not shown, because nothing installs a way out and the reason is the same
    // for every one of them. Rendering seventeen cards that each repeat one
    // sentence about the server is noise, and a worse screen than the one it
    // replaced.
    recordStack('docker');

    $names = collect(app(SiteTypeManager::class)->catalog())->pluck('name');

    expect($names)->not->toContain('wordpress')
        ->and($names)->not->toContain('moodle');
});

it('still refuses a filtered type at the API', function () {
    // The assertion that keeps the filter honest. Leaving a card out of the
    // grid is only acceptable while the endpoint says no — otherwise it is a
    // hidden door rather than a closed one.
    recordStack('docker');

    $manager = app(SiteTypeManager::class);
    $wordpress = collect($manager->all())->first(fn ($type) => $type->name() === 'wordpress');

    expect($manager->unavailable($wordpress))->not->toBeNull()
        ->and($manager->unavailable($wordpress)['code'])->toBe(SiteTypeManager::BLOCKED_STACK)
        ->and($manager->unavailable($wordpress)['reason'])->toBe(__('application.unavailable.stack'));
});

it('still offers PHP site types on a PHP stack', function () {
    // The regression that would matter most: gating everything is easy, and
    // gating only the right thing is the job.
    recordStack('lemp');

    $wordpress = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'wordpress');

    // Present AND not stack-blocked: the filter must not have eaten the grid
    // on a stack that does host PHP.
    expect($wordpress)->not->toBeNull()
        ->and($wordpress['unavailable_code'])->not->toBe(SiteTypeManager::BLOCKED_STACK);
});

it('leaves the site-facing setup rows off a container-only server', function () {
    // The symptom that started this, and the fix I got wrong the first time.
    // Marking the database row `recommended => false` left it on the page with
    // its install button, because `recommended` only decides whether setup
    // counts as complete. The row has to be absent.
    recordStack('docker');

    $keys = collect(app(SetupCatalog::class)->toArray()['components'])->pluck('key');

    expect($keys)->not->toContain('database')
        // The same reasoning reaches three more rows: extra PHP versions,
        // extra Node versions and the compiler toolchain all exist for hosted
        // sites. A container builds its dependencies inside its own image.
        ->and($keys)->not->toContain('php')
        ->and($keys)->not->toContain('node')
        ->and($keys)->not->toContain('build_tools')
        // What remains is what serves the panel and the server itself.
        ->and($keys)->toContain('redis')
        ->and($keys)->toContain('fail2ban');
});

it('keeps every setup row on a stack that hosts sites', function () {
    // Filtering everything is easy; filtering only the right rows is the job.
    recordStack('lemp');

    $keys = collect(app(SetupCatalog::class)->toArray()['components'])->pluck('key');

    foreach (['database', 'php', 'build_tools', 'redis', 'fail2ban'] as $key) {
        expect($keys)->toContain($key);
    }
});

it('refuses the database API, not merely the screen', function () {
    // The assertion that makes this real. Hiding the Databases screen while
    // every endpoint still answers is a worse state than showing it, because
    // nobody is looking for the thing that still works.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    recordStack('docker');

    $this->actingAs($admin)->getJson('/api/databases')->assertStatus(409);
    $this->actingAs($admin)->getJson('/api/databases/engines')->assertStatus(409);
});

it('allows the database API on a stack that hosts database-backed apps', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    recordStack('lemp');

    $this->actingAs($admin)->getJson('/api/databases')->assertOk();
});

it('translates both refusals in every locale', function () {
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        expect(__('errors/server.databases_not_managed', [], $locale))
            ->not->toBe('errors/server.databases_not_managed', "server refusal missing in {$locale}");

        expect(__('application.unavailable.stack', [], $locale))
            ->not->toBe('application.unavailable.stack', "site-type refusal missing in {$locale}");
    }
});
