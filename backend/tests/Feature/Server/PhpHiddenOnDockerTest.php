<?php

use App\Models\ServerCapability;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * PHP is not a thing a Docker box does.
 *
 * `--stack=docker` serves containers and nothing else, so versions, ini files and
 * extensions are a screen about nothing there. Reported as "hide the PHP tab",
 * which is half of it: hiding the tab while the seventeen endpoints still answer
 * is the state `EnsureServerManagesDatabases` warns about in its own docblock — a
 * hidden button whose route works, that nobody is looking for.
 *
 * The case these tests exist for is the THIRD one, not the first two: a server
 * with no recorded capability row. Those are real, migrated in from another panel,
 * and certainly serving PHP. Refusing them their PHP screen because nobody wrote a
 * row would be a far worse bug than one extra tab on a Docker box.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function dockerStackAs(string $stack, array $profiles): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => $stack,
        'web_server' => 'nginx',
        'capabilities' => [
            // True on a Docker box too, and deliberately: PHP IS installed,
            // because the panel is a Laravel application. Every assertion below
            // is about `serving_profiles`, which is the different question.
            'php' => true,
            'node' => false,
            'serving_profiles' => $profiles,
        ],
        'source' => 'installer',
        'verified_at' => now(),
    ]);
}

function phpTabHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** Is `php` in the server-level menu this user would be shown? */
function phpInSidebar(): bool
{
    $response = test()->withHeaders(phpTabHeaders())->getJson('/api/permissions?level=server');

    return collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'php');
}

/*
 * The sidebar.
 */

it('hides the PHP tab on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    expect(phpInSidebar())->toBeFalse();
});

it('keeps the PHP tab on every stack that serves PHP sites', function (string $stack) {
    dockerStackAs($stack, ['php', 'static']);

    expect(phpInSidebar())->toBeTrue();
})->with(['lemp', 'lamp', 'ols']);

it('keeps the PHP tab on a server with no recorded capabilities', function () {
    // The migrated-in box. `ServerCapabilities::DEFAULT_PROFILES` is permissive on
    // purpose and this is the assertion that keeps it that way.
    ServerCapability::query()->delete();

    expect(phpInSidebar())->toBeTrue();
});

it('hides it from the unfiltered menu too, not only from level=server', function () {
    // The sidebar asks with a level; other callers do not. A filter that only
    // applied to one of them would hide the tab and leave every page gate saying
    // the user may still view it.
    dockerStackAs('docker', ['docker']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/permissions');

    expect(collect($response->json('permissions'))->contains(fn (array $item) => $item['name'] === 'php'))
        ->toBeFalse();
});

it('leaves the other server tabs alone', function () {
    // Deliberately NOT widened: Databases and Node.js on a Docker box, and Docker
    // on a LEMP box, are the same argument — but how much of the sidebar vanishes
    // is a product decision, and this test records that it was left undecided
    // rather than overlooked.
    dockerStackAs('docker', ['docker']);

    $names = collect($this->withHeaders(phpTabHeaders())->getJson('/api/permissions?level=server')->json('permissions'))
        ->pluck('name');

    expect($names)->toContain('database')->toContain('node')->toContain('docker');
});

/*
 * The endpoints. Hiding without gating is the worse state.
 */

it('refuses every PHP endpoint on a Docker server', function () {
    dockerStackAs('docker', ['docker']);

    foreach ([
        ['getJson', '/api/php'],
        ['putJson', '/api/php/default'],
        ['postJson', '/api/php/versions'],
        ['getJson', '/api/php/versions/8.4/ini'],
        ['getJson', '/api/php/versions/8.4/extensions'],
        ['getJson', '/api/php/versions/8.4/ioncube'],
    ] as [$method, $url]) {
        $this->withHeaders(phpTabHeaders())->{$method}($url, [])->assertStatus(409);
    }
});

it('says why, rather than answering 409 with nothing', function () {
    dockerStackAs('docker', ['docker']);

    $response = $this->withHeaders(phpTabHeaders())->getJson('/api/php')->assertStatus(409);

    expect($response->json('message'))->toBe(__('errors/php.not_a_php_server'))
        // The sentence has to name the PANEL's own PHP, or the first question back
        // is "so how is the panel running?"
        ->and($response->json('message'))->toContain('panel');
});

it('does not refuse them on a LEMP server', function () {
    dockerStackAs('lemp', ['php', 'static']);

    // Not asserting 200: the endpoint shells out to the box and this suite has no
    // PHP versions installed. Anything other than the capability refusal is what
    // matters here.
    $this->withHeaders(phpTabHeaders())->getJson('/api/php')->assertStatus(200);
});

it('has the refusal translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('errors/php.not_a_php_server', [], $locale);

        expect($line)->not->toBe('errors/php.not_a_php_server')->and($line)->not->toBeEmpty();
    }
});
