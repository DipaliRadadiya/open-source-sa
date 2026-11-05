<?php

use App\Services\Applications\Types\GitSiteType;
use App\Services\Applications\Types\PhpSiteType;
use App\Services\Applications\Types\StaticSiteType;

/**
 * The basic starting points are offered under the Popular filter as well as
 * under Development (Dipali, 2026-11-05). Popular is a filter, not a category,
 * so neither leaves Development.
 */
it('offers blank PHP, static and git under Popular', function (string $class) {
    $type = new $class;

    expect($type->popular())->toBeTrue()
        ->and($type->category())->toBe('developer');
})->with([
    'blank PHP' => [PhpSiteType::class],
    'static' => [StaticSiteType::class],
    'git' => [GitSiteType::class],
]);
