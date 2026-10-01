<?php

use App\Services\Server\Php\AdditionalDirectives;

/*
 * The site owner's additional directives are PHP settings, written as
 * php_admin_value/flag in a PHP-FPM pool and as name = value in an
 * OpenLiteSpeed site's php.ini. Nothing else reaches either file.
 */

it('turns plain settings into the FPM form, flags as flags', function () {
    expect(app(AdditionalDirectives::class)->forFpm("memory_limit = 256M\nlog_errors = on\nshort_open_tag = Off"))
        ->toBe("php_admin_value[memory_limit] = 256M\nphp_admin_flag[log_errors] = on\nphp_admin_flag[short_open_tag] = Off");
});

it('keeps an FPM-form line as the owner wrote it', function () {
    expect(app(AdditionalDirectives::class)->forFpm('php_value[upload_max_filesize] = 64M'))
        ->toBe('php_value[upload_max_filesize] = 64M');
});

it('writes the ini form for OpenLiteSpeed', function () {
    expect(app(AdditionalDirectives::class)->forIni("php_admin_value[memory_limit] = 256M\nerror_reporting = E_ALL & ~E_DEPRECATED"))
        ->toBe("memory_limit = 256M\nerror_reporting = E_ALL & ~E_DEPRECATED");
});

it('skips — never writes — a line that is not a PHP setting', function (string $line) {
    $directives = app(AdditionalDirectives::class);

    expect($directives->firstInvalidLine("memory_limit = 1G\n{$line}"))->toBe(trim($line))
        ->and($directives->forFpm("memory_limit = 1G\n{$line}"))->toBe('php_admin_value[memory_limit] = 1G')
        ->and($directives->forIni("memory_limit = 1G\n{$line}"))->toBe('memory_limit = 1G');
})->with([
    'no equals sign' => ['just some text'],
    'section header' => ['[another]'],
    'bracket in a value' => ['php_value[x] = a]b'],
    'bad name' => ['bad name = 1'],
]);

it('accepts quoted values and ignores comments and blank lines', function () {
    $directives = app(AdditionalDirectives::class);

    expect($directives->firstInvalidLine("; comment\n# also a comment\n\nsession.name = \"MYSESS[1]\"\n"))->toBeNull()
        ->and($directives->forIni('session.name = "MYSESS[1]"'))->toBe('session.name = "MYSESS[1]"');
});

it('names why a line cannot be saved', function (string $text, ?string $reason, ?string $name) {
    $refusal = app(AdditionalDirectives::class)->refusal($text);

    expect($refusal['reason'] ?? null)->toBe($reason)
        ->and($refusal['name'] ?? null)->toBe($name);
})->with([
    'unmanaged setting' => ["display_errors = Off\n; memory_limit = 1G", null, null],
    'panel-managed' => ['display_errors = Off'."\n".'php_admin_value[post_max_size] = 1G', 'directive_managed', 'post_max_size'],
    'extension' => ['extension = redis.so', 'directive_extension', 'extension'],
    'zend_extension' => ['zend_extension=opcache', 'directive_extension', 'zend_extension'],
    'not a setting first' => ["nonsense\nmemory_limit = 1G", 'directive_invalid', ''],
]);

it('still writes a panel-managed line saved before it was refused', function () {
    // Refused when saved, not dropped from existing sites: what was applied
    // yesterday is applied today.
    expect(app(AdditionalDirectives::class)->forIni('memory_limit = 1G'))->toBe('memory_limit = 1G');
});
