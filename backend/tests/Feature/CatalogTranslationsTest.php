<?php

use App\Models\Permission;
use Database\Seeders\PermissionSeeder;

/*
 * Every permission group needs a `nav_group` entry in every language, or its
 * header falls back to the raw word and shows in English.
 */
it('names every permission group in every language', function (string $locale) {
    $this->seed(PermissionSeeder::class);

    foreach (Permission::query()->distinct()->pluck('sub_level') as $subLevel) {
        expect(trans()->has('nav_group.'.$subLevel, $locale, false))->toBeTrue("nav_group.{$subLevel} missing in {$locale}");
    }
})->with(['en', 'de', 'es', 'fr', 'hi', 'ja', 'pt', 'ru']);

/*
 * A bot listed twice in two spellings (`meta-externalagent`) inflates the
 * count. Every web server matches these case-insensitively, so a second
 * spelling never blocks anything more.
 */
it('lists each AI bot once, whatever its case', function (string $bucket) {
    $names = array_map(mb_strtolower(...), (array) config('ai_bots.'.$bucket));

    expect($names)->toBe(array_values(array_unique($names)));
})->with(['training', 'search', 'agent']);
