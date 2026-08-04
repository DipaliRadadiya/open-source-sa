<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/*
 * Field labels are localized on the backend so the frontend never holds a
 * label list. That only works if every declared field actually has a
 * translation — otherwise the API ships the raw key ("shop_name") and the
 * frontend has to patch around it, which is exactly what happened for
 * PrestaShop. Twelve fields were missing when this test was written.
 */

/** @return list<string> every field name any site type declares */
function declaredFieldNames(): array
{
    $names = [];

    foreach (glob(app_path('Services/Applications/Types/*SiteType.php')) as $file) {
        preg_match_all("/\\\$this->field\('([a-z_]+)'/", (string) file_get_contents($file), $matches);
        $names = array_merge($names, $matches[1]);
    }

    return array_values(array_unique($names));
}

it('has a label for every field, in every locale', function () {
    $declared = declaredFieldNames();

    expect($declared)->not->toBeEmpty();

    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);
        $translated = array_keys(__('application.fields'));

        expect(array_diff($declared, $translated))
            ->toBe([], "missing application.fields labels in [{$locale}]");
    }
});

it('never sends a raw key as a label', function () {
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach (__('application.fields') as $key => $label) {
            // A label identical to its key means the translation is absent and
            // Laravel echoed the key back.
            expect($label)->not->toBe($key)
                ->and($label)->not->toBe("application.fields.{$key}");
        }
    }
});

it('labels the PrestaShop fields the frontend reported', function () {
    app()->setLocale('en');

    expect(__('application.fields.shop_name'))->toBe('Shop name')
        ->and(__('application.fields.admin_first_name'))->toBe('Administrator first name')
        ->and(__('application.fields.admin_last_name'))->toBe('Administrator last name');
});

it('serializes empty application settings as an object, not a list', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $systemUser = SystemUser::create(['username' => 'shapes', 'home_path' => '/home/shapes']);
    $application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'No settings',
        'domain' => 'shapes.example.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/applications/{$application->id}");

    $response->assertOk();

    // PHP cannot tell an empty map from an empty list, so json_encode picks
    // the array. Without the cast the field's shape depends on whether it
    // happens to be populated, and every consumer has to handle both.
    expect($response->getContent())->toContain('"settings":{}')
        ->and($response->getContent())->not->toContain('"settings":[]');
});

it('still serializes populated settings as an object', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $systemUser = SystemUser::create(['username' => 'shapes2', 'home_path' => '/home/shapes2']);
    $application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'With settings',
        'domain' => 'shapes2.example.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'settings' => ['table_prefix' => 'ps_'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/applications/{$application->id}");

    $response->assertOk()
        ->assertJsonPath('application.settings.table_prefix', 'ps_');
});

it('keeps steps as a list — it is a genuine array', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $systemUser = SystemUser::create(['username' => 'shapes3', 'home_path' => '/home/shapes3']);
    $application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Steps',
        'domain' => 'shapes3.example.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/applications/{$application->id}");

    $response->assertOk();
    expect($response->getContent())->toContain('"steps":[]');
});
