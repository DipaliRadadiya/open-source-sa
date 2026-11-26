<?php

use App\Models\Permission;
use App\Services\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Sidebar labels come from `nav.*`, resolved in the viewer's locale at read time. The
 * `permissions.title` column is only a fallback for a permission with no nav key, which fires
 * for none today, so it can drift unnoticed.
 *
 * These pin both halves: the fallback stays unreachable, and it says the same as the label it
 * would replace.
 */

/** @return array<int, string> */
function navLocales(): array
{
    return ['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'];
}

it('has a nav key for every permission, in every locale', function () {
    $names = collect(app(PermissionCatalog::class)->items())->pluck('name');

    foreach (navLocales() as $locale) {
        $keys = array_keys(trans('nav', [], $locale));

        $missing = $names->reject(fn (string $name) => in_array($name, $keys, true))->all();

        expect($missing)->toBe([], "{$locale} is missing nav keys: ".implode(', ', $missing));
    }
});

/*
 * The reverse direction: a key left behind after a permission is renamed or removed is a
 * translated string nothing renders.
 */
it('has no nav key without a permission behind it', function () {
    $names = collect(app(PermissionCatalog::class)->items())->pluck('name')->all();

    $orphans = array_values(array_diff(array_keys(trans('nav')), $names));

    expect($orphans)->toBe([]);
});

/*
 * Not a style rule, a drift check: the column is invisible, so nothing else shows a mismatch.
 */
it('keeps the unreachable fallback saying what the English label says', function () {
    foreach (app(PermissionCatalog::class)->items() as $item) {
        expect($item['title'])->toBe(
            trans('nav.'.$item['name'], [], 'en'),
            "catalog title for {$item['name']} disagrees with nav.en"
        );
    }
});

/*
 * A list page is named for the things it lists ("Workers", "Files", "Backups"), at app and
 * server level alike.
 */
it('names a list page in the plural', function () {
    $this->seed(PermissionSeeder::class);

    $titles = Permission::query()->pluck('name')->mapWithKeys(
        fn (string $name) => [$name => Permission::firstWhere('name', $name)->localizedTitle()]
    );

    expect($titles['application'])->toBe('Applications')
        ->and($titles['database'])->toBe('Databases')
        ->and($titles['system_user'])->toBe('System Users')
        ->and($titles['service'])->toBe('Services')
        ->and($titles['setting'])->toBe('Settings')
        ->and($titles['cronjob'])->toBe('Cron Jobs')
        ->and($titles['app_deployment'])->toBe('Deployments');
});

/*
 * Singular where the page is one thing, so a later sweep does not pluralise them: a firewall
 * is one firewall, an environment is one file, fail2ban is a product name.
 */
it('leaves a page that is not a list in the singular', function () {
    $this->seed(PermissionSeeder::class);

    foreach (['dashboard' => 'Dashboard', 'firewall' => 'Firewall', 'fail2ban' => 'Fail2ban',
        'app_environment' => 'Environment', 'disk_cleaner' => 'Disk Cleaner'] as $name => $expected) {
        expect(Permission::firstWhere('name', $name)->localizedTitle())->toBe($expected);
    }
});

/*
 * Japanese does not mark plural on nouns; asserted so a "translate the plurals" pass does not
 * invent forms the language lacks.
 */
it('leaves Japanese unpluralised', function () {
    expect(trans('nav.application', [], 'ja'))->toBe('アプリケーション')
        ->and(trans('nav.service', [], 'ja'))->toBe('サービス');
});
