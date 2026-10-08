<?php

use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Support\ProbeCache;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * FS-C46: the read-only screens that ask the server keep the answer for a
 * short while, and anything the panel does that changes it clears it.
 */

beforeEach(function () {
    config(['server.probe_cache_seconds' => 30]);
});

it('asks once and remembers', function () {
    $asked = 0;
    $probe = function () use (&$asked) {
        $asked++;

        return ['v' => $asked];
    };

    expect(ProbeCache::remember('k', $probe))->toBe(['v' => 1])
        ->and(ProbeCache::remember('k', $probe))->toBe(['v' => 1])
        ->and($asked)->toBe(1);
});

it('asks again after a flush', function () {
    $asked = 0;
    $probe = function () use (&$asked) {
        return ++$asked;
    };

    ProbeCache::remember('k', $probe);
    ProbeCache::flush();

    expect(ProbeCache::remember('k', $probe))->toBe(2);
});

it('does not cache when turned off', function () {
    config(['server.probe_cache_seconds' => 0]);
    $asked = 0;

    ProbeCache::remember('k', function () use (&$asked) {
        return ++$asked;
    });
    ProbeCache::remember('k', function () use (&$asked) {
        return ++$asked;
    });

    expect($asked)->toBe(2);
});

it('is cleared by an install starting and finishing', function () {
    $asked = 0;
    $probe = function () use (&$asked) {
        return ++$asked;
    };

    ProbeCache::remember('k', $probe);
    app(InstallTracker::class)->start('php', '8.3');
    expect(ProbeCache::remember('k', $probe))->toBe(2);

    app(InstallTracker::class)->succeed('php', '8.3');
    expect(ProbeCache::remember('k', $probe))->toBe(3);
});

it('serves the settings screen without asking the server twice', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $ran = 0;
    Process::fake(function () use (&$ran) {
        $ran++;

        return Process::result();
    });

    $this->actingAs($admin)->getJson('/api/settings')->assertOk();
    $first = $ran;

    $this->actingAs($admin)->getJson('/api/settings')->assertOk();

    expect($first)->toBeGreaterThan(0)
        ->and($ran)->toBe($first);
});
