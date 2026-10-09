<?php

use Illuminate\Support\Facades\Process;

it('regenerates the shipped Vaultwarden template byte for byte without bootstrapping Laravel', function () {
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('tests/Support/Recipes/template-from-golden.php'),
        'vaultwarden',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->errorOutput())->toBe('')
        ->and($result->output())->toBe(file_get_contents(resource_path('recipes/vaultwarden/compose.yml.tpl')));
});

it('refuses a slug that could escape the golden fixture directory', function () {
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('tests/Support/Recipes/template-from-golden.php'),
        '../vaultwarden',
    ]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('')
        ->and($result->errorOutput())->toContain('Usage:');
});

it('fails without emitting a partial template when the golden fixture is missing', function () {
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('tests/Support/Recipes/template-from-golden.php'),
        'no_such_recipe',
    ]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('')
        ->and($result->errorOutput())->toContain('Cannot read');
});
