<?php

use App\Services\Server\Databases\DatabasePassword;

// FS-C22: PrestaShop cut a generated password at `=`; `+` is a space in a URL.
it('generates passwords with no separator characters', function () {
    $passwords = implode('', array_map(fn () => DatabasePassword::generate(), range(1, 500)));

    expect($passwords)->not->toContain('=')
        ->and($passwords)->not->toContain('+')
        ->and($passwords)->toMatch('/^[A-Za-z0-9._-]+$/');
});

it('keeps the length it is asked for', function () {
    expect(strlen(DatabasePassword::generate()))->toBe(20)
        ->and(strlen(DatabasePassword::generate(32)))->toBe(32);
});
