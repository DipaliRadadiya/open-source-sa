<?php

use App\Models\Application;
use App\Models\RuntimeLifecycle;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Runtime\LifecycleCatalog;
use App\Services\Runtime\PinnedSites;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->systemUser = SystemUser::create([
        'username' => 'p', 'home_path' => '/home/p', 'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function pinSite(string $name, ?string $php = null, ?string $node = null): Application
{
    return Application::create([
        'system_user_id' => test()->systemUser->id, 'name' => $name, 'domain' => "{$name}.test",
        'site_type' => 'php', 'serving_profile' => 'php', 'web_root' => '/', 'status' => 'pending',
        'php_version' => $php, 'node_version' => $node,
    ]);
}

it('names the sites pinned to a version, not just how many', function () {
    pinSite('shop', php: '8.3');
    pinSite('blog', php: '8.3');
    pinSite('elsewhere', php: '8.4');

    $summary = app(PinnedSites::class)->summary('php_version');

    // "2 sites" does not tell you whether removing this breaks staging or the
    // shop.
    expect($summary['8.3']['count'])->toBe(2)
        ->and($summary['8.3']['names'])->toBe(['blog', 'shop'])
        ->and($summary['8.3']['truncated'])->toBeFalse();
});

it('caps the names in a list response but keeps the count honest', function () {
    config(['server.runtimes.pinned_sites_shown' => 3]);

    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        pinSite($name, php: '8.3');
    }

    $summary = app(PinnedSites::class)->summary('php_version');

    // Eighty names would otherwise ride along in a payload the screen loads
    // on every visit. The count is the truth; the names are a sample.
    expect($summary['8.3']['names'])->toHaveCount(3)
        ->and($summary['8.3']['count'])->toBe(5)
        ->and($summary['8.3']['truncated'])->toBeTrue();
});

it('names every site in the refusal, where completeness is the point', function () {
    config(['server.runtimes.pinned_sites_shown' => 2]);

    foreach (['a', 'b', 'c', 'd'] as $name) {
        pinSite($name, node: '20.11.0');
    }

    // A single-item response, so no cap: you need all of them to act.
    expect(app(PinnedSites::class)->allFor('node_version', '20.11.0'))->toBe(['a', 'b', 'c', 'd']);
});

it('reads Node LTS from the release schedule, not from even major numbers', function () {
    Http::fake([
        '*nodejs*' => Http::response([
            'v22' => ['lts' => '2024-10-29', 'maintenance' => '2099-01-01', 'end' => '2099-04-30', 'codename' => 'Jod'],
            // Odd majors never become LTS — the parity rule is a convention,
            // not a fact, and this one is already dead.
            'v23' => ['maintenance' => '2025-04-01', 'end' => '2025-06-01'],
        ]),
        '*endoflife*' => Http::response([]),
    ]);

    app(LifecycleCatalog::class)->refresh();
    $catalog = app(LifecycleCatalog::class);

    expect($catalog->for('node', '22.11.0'))->toBe(['status' => 'lts', 'eol_date' => '2099-04-30', 'lts_name' => 'Jod'])
        ->and($catalog->for('node', '23.0.0')['status'])->toBe('eol')
        ->and($catalog->for('node', '23.0.0')['lts_name'])->toBeNull();
});

it('gives PHP no lts field, because PHP has no LTS releases', function () {
    Http::fake([
        '*nodejs*' => Http::response([]),
        '*endoflife*' => Http::response([
            ['cycle' => '8.4', 'support' => '2099-12-31', 'eol' => '2099-12-31'],
            ['cycle' => '8.1', 'support' => '2023-11-25', 'eol' => '2025-12-31'],
        ]),
    ]);

    app(LifecycleCatalog::class)->refresh();
    $catalog = app(LifecycleCatalog::class);

    // Active, then security-only, then dead. Inventing an `lts` badge would
    // show users something that does not exist upstream.
    expect($catalog->for('php', '8.4'))->toBe(['status' => 'active', 'eol_date' => '2099-12-31'])
        ->and($catalog->for('php', '8.1')['status'])->toBe('eol')
        ->and($catalog->for('php', '8.1'))->not->toHaveKey('lts_name');
});

it('reports security-only for a PHP version past active support', function () {
    Http::fake([
        '*nodejs*' => Http::response([]),
        '*endoflife*' => Http::response([
            ['cycle' => '8.2', 'support' => '2020-01-01', 'eol' => '2099-12-31'],
        ]),
    ]);

    app(LifecycleCatalog::class)->refresh();

    expect(app(LifecycleCatalog::class)->for('php', '8.2')['status'])->toBe('security');
});

it('returns no lifecycle rather than a guess when there is no data', function () {
    // A box with no egress. The frontend hides the badges; it must not be
    // told every version is unknown-and-therefore-suspect.
    expect(app(LifecycleCatalog::class)->isStale())->toBeTrue()
        ->and(app(LifecycleCatalog::class)->for('node', '22.0.0'))->toBeNull();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/php');

    expect($response->json('php.lifecycle_available'))->toBeFalse();
});

it('keeps yesterday data when the refresh cannot reach upstream', function () {
    RuntimeLifecycle::create(['runtime' => 'node', 'version' => '22', 'status' => 'lts', 'eol_date' => '2027-04-30']);

    Http::fake(fn () => throw new RuntimeException('no route to host'));

    app(LifecycleCatalog::class)->refresh();

    // A network blip must not blank out badges that were correct yesterday.
    expect(app(LifecycleCatalog::class)->for('node', '22.11.0')['status'])->toBe('lts');
});

it('survives the cache being cleared, because it is data and not a cache', function () {
    RuntimeLifecycle::create(['runtime' => 'php', 'version' => '8.4', 'status' => 'active', 'eol_date' => '2028-12-31']);

    // `php artisan optimize:clear` runs on deploy. Reference data that
    // vanishes on deploy is a bug, not a cold cache.
    $this->artisan('cache:clear')->assertSuccessful();

    expect(app(LifecycleCatalog::class)->for('php', '8.4')['status'])->toBe('active');
});

it('never makes a network call while answering a request', function () {
    Http::fake(fn () => throw new RuntimeException('a request must not reach the network'));

    $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/php')->assertOk();
});
