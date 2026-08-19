<?php

use App\Models\Permission;
use App\Services\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Sidebar labels come from `nav.*`, resolved in the viewer's locale at read
 * time. The `permissions.title` column is only reached when a permission has no
 * nav key — a fallback that fires for none of them today, which is exactly what
 * lets it drift unnoticed: the catalog said "Logs" and "Backup" for months
 * while the screen showed "System Logs" and "Backups".
 *
 * These pin both halves: that the fallback stays unreachable, and that it says
 * the same thing as the label it would replace if it ever were reached.
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
 * The reverse direction. A key left behind after a permission is renamed or
 * removed is a translated string nothing renders, and eight copies of it.
 */
it('has no nav key without a permission behind it', function () {
    $names = collect(app(PermissionCatalog::class)->items())->pluck('name')->all();

    $orphans = array_values(array_diff(array_keys(trans('nav')), $names));

    expect($orphans)->toBe([]);
});

/*
 * Not a style rule — a drift check. The column is invisible, so nothing else
 * would ever tell us the two disagree.
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
 * The plural rename itself. A list page is named for the things it lists —
 * every app-level key already did this ("Workers", "Files", "Backups") while
 * the server-level ones did not, so the two halves of one file disagreed.
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
 * Singular where the page is one thing, so a later sweep does not "fix" these
 * into plurals that would be wrong. A firewall contains rules but is one
 * firewall; an environment is one file; fail2ban is a product name.
 */
it('leaves a page that is not a list in the singular', function () {
    $this->seed(PermissionSeeder::class);

    foreach (['dashboard' => 'Dashboard', 'firewall' => 'Firewall', 'fail2ban' => 'Fail2ban',
        'app_environment' => 'Environment', 'disk_cleaner' => 'Disk Cleaner'] as $name => $expected) {
        expect(Permission::firstWhere('name', $name)->localizedTitle())->toBe($expected);
    }
});

/*
 * Japanese does not mark plural on nouns, so its labels are unchanged by this
 * work. Asserted so a future "translate the plurals" pass does not invent forms
 * the language does not have.
 */
it('leaves Japanese unpluralised', function () {
    expect(trans('nav.application', [], 'ja'))->toBe('アプリケーション')
        ->and(trans('nav.service', [], 'ja'))->toBe('サービス');
});
