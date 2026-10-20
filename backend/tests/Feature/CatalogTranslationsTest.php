<?php

use App\Models\Permission;
use Database\Seeders\PermissionSeeder;

/*
 * RC-25 (frontend QA, 17 Oct): the permission group "Application" showed in
 * English in every language — nav_group.php had `server` and `integration`
 * and no `application`, so the header fell back to the raw word.
 */
it('names every permission group in every language', function (string $locale) {
    $this->seed(PermissionSeeder::class);

    foreach (Permission::query()->distinct()->pluck('sub_level') as $subLevel) {
        expect(trans()->has('nav_group.'.$subLevel, $locale, false))->toBeTrue("nav_group.{$subLevel} missing in {$locale}");
    }
})->with(['en', 'de', 'es', 'fr', 'hi', 'ja', 'pt', 'ru']);

/*
 * AB-C (frontend QA, 26 Sep): `meta-externalagent` was listed twice, in two
 * spellings, so the count read 23 for 22 bots. Every web server matches these
 * case-insensitively, so a second spelling never blocks anything more.
 */
it('lists each AI bot once, whatever its case', function (string $bucket) {
    $names = array_map(mb_strtolower(...), (array) config('ai_bots.'.$bucket));

    expect($names)->toBe(array_values(array_unique($names)));
})->with(['training', 'search', 'agent']);
