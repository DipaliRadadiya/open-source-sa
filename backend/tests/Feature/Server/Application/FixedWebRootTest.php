<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Craft and Statamic can only be served from one directory: that is a *rule*, not a default.
 *
 * A `web_root` other than the type's own points the site at a directory its installer never
 * creates: it 403s, and the directory it does serve is the application itself (`.env` with the
 * database password, `vendor/`, Statamic's `storage/`).
 */
beforeEach(function () {
    // The catalog refuses a database-backed type when no engine answers.
    fakeUsableSqlEngine();

    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->systemUser = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner',
    ]);

    Queue::fake();
});

/** The type's own required fields, so the request fails on web_root or nothing. */
function typeFields(string $type): array
{
    return match ($type) {
        'craftcms' => [
            'site_name' => 'My Craft Site',
            'admin_user' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'SomethingLong1!',
        ],
        'statamic' => [
            'admin_email' => 'admin@example.com',
            'admin_password' => 'SomethingLong1!',
        ],
        default => [],
    };
}

function createTyped(string $type, array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson('/api/applications', array_merge([
        'site_type' => $type,
        'name' => 'Site '.uniqid(),
        'domain' => uniqid('s').'.example.com',
        'system_user_id' => test()->systemUser->id,
        'php_version' => '8.4',
    ], typeFields($type), $overrides));
}

it('refuses to serve Craft from anywhere but its own web directory', function () {
    createTyped('craftcms', ['web_root' => '/'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('web_root');

    expect(Application::count())->toBe(0);
})->skip(fn () => app(SiteTypeManager::class)->find('craftcms') === null, 'craftcms not registered');

it('says which value the type needs, rather than "the selected web root is invalid"', function () {
    $response = createTyped('statamic', ['web_root' => '/public_html'])
        ->assertStatus(422);

    // A caller can hit this innocently, so the message must name the value the type needs.
    expect($response->json('errors.web_root.0'))->toContain('/public');
});

it('accepts the value the type declares', function () {
    createTyped('craftcms', ['web_root' => '/web'])->assertCreated();

    expect(Application::first()->web_root)->toBe('/web');
});

it('accepts the same value without its leading slash', function () {
    // The field is free text: rejecting a value that names the right directory would be pedantic.
    createTyped('statamic', ['web_root' => 'public'])->assertCreated();
});

it('still falls back to the default when the field is omitted', function () {
    createTyped('craftcms')->assertCreated();

    expect(Application::first()->web_root)->toBe('/web');
});

it('leaves every other type free to choose', function () {
    // WordPress unpacks into whatever directory the vhost points at: the web root is the
    // user's call.
    createTyped('wordpress', [
        'web_root' => '/anything',
        'site_title' => 'Shop',
        'admin_user' => 'admin',
        'admin_email' => 'admin@example.com',
        'admin_password' => 'SomethingLong1!',
    ])->assertCreated();

    expect(Application::first()->web_root)->toBe('/anything');
});

it('declares a fixed web root only where the installer builds one', function () {
    $fixed = [];

    foreach (app(SiteTypeManager::class)->all() as $type) {
        if ($type->fixedWebRoot() !== null) {
            $fixed[$type->name()] = $type->fixedWebRoot();
        }
    }

    // A new type here needs an installer that builds that layout; a removed one is now
    // misconfigurable.
    expect($fixed)->toBe(['craftcms' => '/web', 'statamic' => '/public']);
});
