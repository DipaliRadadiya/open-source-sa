<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Validator;

/*
 * Field labels are localized on the backend so the frontend never holds a label list. That only
 * works if every declared field has a translation: otherwise the API ships the raw key
 * ("shop_name") and the frontend has to patch around it.
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

/*
 * Placeholders and defaults are not interchangeable. A placeholder is ghost text that is never
 * submitted; a default is a real value that is. A placeholder on `table_prefix` shows a grey
 * `wp_`, the user leaves it alone, and the request carries null.
 */
it('never gives a field both a default and a placeholder', function () {
    $both = [];
    $seen = 0;

    foreach (app(SiteTypeManager::class)->all() as $type) {
        foreach ($type->fields() as $field) {
            $seen++;

            // The pre-filled value hides the ghost text, so a field carrying
            // both was written under a misapprehension about which does what.
            if (isset($field['default'], $field['placeholder'])) {
                $both[] = $type->name().'.'.$field['name'];
            }
        }
    }

    // Asserted, not assumed: a loop over an empty catalogue passes this while
    // checking nothing, and would keep passing if fields() ever returned [].
    expect($seen)->toBeGreaterThan(50)
        ->and($both)->toBe([], 'both a default and a placeholder: '.implode(', ', $both));
});

it('offers no default its own validation rules would reject', function () {
    // A pre-filled value the API refuses opens the form already invalid. The two sit in the same
    // file and are easy to change one at a time (`akt_` had to fit a lowercase-only rule, `jml_`
    // a length its neighbours do not share).
    $checked = 0;

    foreach (app(SiteTypeManager::class)->all() as $type) {
        $rules = $type->rules();

        foreach ($type->fields() as $field) {
            if (! filled($field['default'] ?? null) || ! isset($rules[$field['name']])) {
                continue;
            }

            $checked++;

            $validator = Validator::make(
                [$field['name'] => $field['default']],
                [$field['name'] => $rules[$field['name']]],
            );

            expect($validator->fails())->toBeFalse(
                "{$type->name()}.{$field['name']} defaults to '{$field['default']}', which its own rules reject: "
                .implode(' ', $validator->errors()->all())
            );
        }
    }

    expect($checked)->toBeGreaterThan(5);
});

it('gives every free-text field an example or a value to start from', function () {
    $missing = [];

    foreach (app(SiteTypeManager::class)->all() as $type) {
        foreach ($type->fields() as $field) {
            // Only the fields someone types prose into. A password is
            // generated, a select already lists its answers, and a number with
            // min/max says its own shape — an example is noise in all three.
            if (! in_array($field['type'], ['text', 'email', 'url', 'textarea'], true)) {
                continue;
            }

            // Handled by the form itself rather than by the field schema: the
            // frontend owns the name and domain boxes and has its own copy for
            // them, and a git field's answer comes from the connected account.
            if (in_array($field['name'], ['name', 'domain', 'repository_url'], true)) {
                continue;
            }

            // `help` counts. A sentence under the box describing the value is a
            // better example than ghost text, and demanding both would push
            // duplicate copy onto the fields that already read well.
            if (filled($field['default'] ?? null)
                || filled($field['placeholder'] ?? null)
                || filled($field['help'] ?? null)) {
                continue;
            }

            $missing[] = $type->name().'.'.$field['name'];
        }
    }

    expect($missing)->toBe([], 'fields with nothing to go on: '.implode(', ', $missing));
});

it('shows a bare example, not a sentence about one', function () {
    // Ghost text is already understood as an example, so a literal 'e.g.' prefix inside the value
    // is wasted space and, hardcoded in English, shows "e.g." beside translated examples.
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach (app(SiteTypeManager::class)->all() as $type) {
            foreach ($type->fields() as $field) {
                if (! filled($field['placeholder'] ?? null)) {
                    continue;
                }

                expect(mb_strtolower((string) $field['placeholder']))
                    ->not->toStartWith('e.g.', "{$type->name()}.{$field['name']} explains its example instead of being one")
                    ->not->toStartWith('example', "{$type->name()}.{$field['name']} explains its example instead of being one");
            }
        }
    }
});

it('has a translation for every placeholder and help string a field references', function () {
    // These go out through the API in the caller's locale: an untranslated one ships the raw key
    // (`application.placeholders.site_title`) into a form field.
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach (app(SiteTypeManager::class)->all() as $type) {
            foreach ($type->fields() as $field) {
                foreach (['placeholder', 'help'] as $key) {
                    if (! filled($field[$key] ?? null)) {
                        continue;
                    }

                    expect($field[$key])->not->toStartWith('application.',
                        "{$type->name()}.{$field['name']} {$key} is an untranslated key in [{$locale}]");
                }
            }
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
    $application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'No settings',
        'slug' => 'no-settings',
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
    $application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'With settings',
        'slug' => 'with-settings',
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
    $application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Steps',
        'slug' => 'steps',
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

/*
 * Missing syncUrl is only a bug when the application persists a URL. This pins the answer for each
 * application, so the next reader of the no-op list does not have to re-derive it, and an
 * installer that starts writing a URL gets a failing test, not a silent mixed-content shop.
 */
it('implements syncUrl for exactly the installers that persist a URL', function () {
    $implements = [];

    foreach (glob(app_path('Services/Server/Applications/Installers/*Installer.php')) as $file) {
        $name = basename($file, '.php');

        if (str_starts_with($name, 'Abstract')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), 'function syncUrl(')) {
            $implements[] = $name;
        }
    }

    sort($implements);

    expect($implements)->toBe([
        // Each stores a canonical URL of its own: wp_options, config.php's
        // wwwroot, .env keys, config.json, occ's overwrite.cli.url, local.php's
        // site_url, and PrestaShop's shop_url rows.
        'AkauntingInstaller',
        'CraftCmsInstaller',
        // The container apps' URL lives in the compose file the panel renders —
        // Ghost's `url` and Strapi's `URL` — so a renamed site needs the file
        // rewritten and the container brought up again, not a config edit.
        'DockerAppInstaller',
        'MauticInstaller',
        'MoodleInstaller',
        'N8nInstaller',
        'NextcloudInstaller',
        'NodeBbInstaller',
        'PrestaShopInstaller',
        'StatamicInstaller',
        'WordPressInstaller',
    ], 'the installers that persist a URL changed — see the no-op list in AbstractSiteInstaller');

    // The deliberate absences, each checked against its own install command
    // rather than assumed: Joomla is passed no URL and leaves $live_site empty
    // so it detects per request, Node-RED and Uptime Kuma write no URL at all,
    // and phpMyAdmin leaves PmaAbsoluteUri unset.
    foreach (['JoomlaInstaller', 'NodeRedInstaller', 'UptimeKumaInstaller', 'PhpMyAdminInstaller'] as $absent) {
        expect($implements)->not->toContain($absent);
    }
});
