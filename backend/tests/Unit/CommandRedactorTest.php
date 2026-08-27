<?php

use App\Support\CommandRedactor;

it('preserves a full command while masking sensitive values', function () {
    $command = implode(' ', [
        '/usr/bin/php',
        'install.php',
        '--site=example.com',
        '--password=one',
        '--api-token',
        'two',
        'DB_PASSWORD=three',
        'https://user:four@example.com/path?token=five&mode=live',
        'Bearer',
        'six',
    ]);

    $redacted = CommandRedactor::line($command);

    expect($redacted)
        ->toContain('/usr/bin/php install.php')
        ->toContain('--site=example.com')
        ->toContain('--password=[REDACTED]')
        ->toContain('--api-token [REDACTED]')
        ->toContain('DB_PASSWORD=[REDACTED]')
        ->toContain('https://user:[REDACTED]@example.com/path?token=[REDACTED]&mode=live')
        ->toContain('Bearer [REDACTED]')
        ->not->toContain('one')
        ->not->toContain('two')
        ->not->toContain('three')
        ->not->toContain('four')
        ->not->toContain('five')
        ->not->toContain('six');
});

it('uses the same masking policy for argument arrays', function () {
    expect(CommandRedactor::arguments([
        'php',
        'install.php',
        '--adminpass=secret-one',
        '--private-key',
        'secret-two',
        '--site=example.com',
    ]))->toBe(
        'php install.php --adminpass=[REDACTED] --private-key [REDACTED] --site=example.com',
    );
});
