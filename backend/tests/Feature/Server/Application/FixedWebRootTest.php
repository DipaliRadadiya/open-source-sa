<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Craft and Statamic can only be served from one directory, and until now that
 * was a *default* rather than a *rule*.
 *
 * `web_root` is an optional advanced field. Omitted, it fell back to the type's
 * default and everything worked. Sent as anything else — a cleared advanced
 * input, or a form that submits every field it renders — it was accepted, and
 * the site came out pointed at a directory its installer never creates.
 *
 * Two things go wrong then, and the second is the reason this is a rule now:
 * the site 403s on every request, and the directory it *does* serve is the
 * application itself — `.env` with the database password, `vendor/`, and for
 * Statamic `storage/` with every page's content in it.
 */
beforeEach(function () {
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

    // The one rule here a caller can hit while doing something reasonable, so
    // the message has to name the value instead of only rejecting theirs.
    expect($response->json('errors.web_root.0'))->toContain('/public');
});

it('accepts the value the type declares', function () {
    createTyped('craftcms', ['web_root' => '/web'])->assertCreated();

    expect(Application::first()->web_root)->toBe('/web');
});

it('accepts the same value without its leading slash', function () {
    // The field is free text and `web/` is the obvious thing to type. Rejecting
    // a value that means exactly the right directory would be pedantry.
    createTyped('statamic', ['web_root' => 'public'])->assertCreated();
});

it('still falls back to the default when the field is omitted', function () {
    createTyped('craftcms')->assertCreated();

    expect(Application::first()->web_root)->toBe('/web');
});

it('leaves every other type free to choose', function () {
    // WordPress unpacks into whatever directory the vhost points at, so the
    // web root is genuinely the user's decision there. Constraining these
    // would be inventing a restriction rather than recording one.
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

    // If a new type appears here, its installer had better build that layout —
    // and if one disappears, a site type quietly became misconfigurable.
    expect($fixed)->toBe(['craftcms' => '/web', 'statamic' => '/public']);
});
