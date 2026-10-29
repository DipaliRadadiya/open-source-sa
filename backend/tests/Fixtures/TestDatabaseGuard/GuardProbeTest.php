<?php

use Tests\TestCase;

/*
 * Not part of the suite: tests/Fixtures is outside phpunit.xml's testsuites.
 * TestDatabaseGuardTest runs this file in a child process and reads the
 * marker to see whether a test body ran, and on which database.
 */

uses(TestCase::class);

it('reaches the first test body', function () {
    file_put_contents((string) getenv('GUARD_PROBE_MARKER'), json_encode([
        'connection' => config('database.default'),
        'database' => config('database.connections.'.config('database.default').'.database'),
    ]));

    expect(true)->toBeTrue();
});

it('reaches the second test body', function () {
    file_put_contents((string) getenv('GUARD_PROBE_MARKER').'.second', 'ran');

    expect(true)->toBeTrue();
});
