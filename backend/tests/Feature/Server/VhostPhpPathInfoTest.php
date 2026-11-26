<?php

/**
 * The PHP location must reach scripts addressed with a path appended.
 *
 * Moodle uses slash arguments (`/theme/styles.php/boost/<rev>/all`); against an end-anchored
 * `location ~ \.php$` that URL falls through to `location /` and index.php, so the browser
 * gets HTML where it asked for CSS.
 *
 * Asserted against the location's own regex rather than a live nginx: nginx compiles these
 * with PCRE, the same engine running this test.
 */
function nginxPhpLocationPattern(): string
{
    $source = file_get_contents(
        base_path('resources/views/server/vhosts/nginx/php.blade.php')
    );

    // The PHP handler, not the dotfile-deny block that also starts `location ~`.
    preg_match('/location ~ (\S+) \{\s*\n\s*include snippets\/fastcgi-php\.conf;/', $source, $m);

    return $m[1] ?? '';
}

it('finds the php location it is meant to be checking', function () {
    // Otherwise the assertions below run against an empty pattern and pass for the wrong reason.
    expect(nginxPhpLocationPattern())->not->toBe('');
});

it('routes path-info script URLs to PHP, not to index.php', function () {
    $pattern = '#'.nginxPhpLocationPattern().'#';

    // Moodle's slash arguments; other applications address their entry points the same way.
    expect(preg_match($pattern, '/theme/styles.php/boost/1700000000/all'))->toBe(1)
        ->and(preg_match($pattern, '/theme/javascript.php/boost/1700000000/all'))->toBe(1)
        ->and(preg_match($pattern, '/pluginfile.php/1/course/overviewfiles/logo.png'))->toBe(1);
});

it('still routes ordinary php requests', function () {
    $pattern = '#'.nginxPhpLocationPattern().'#';

    expect(preg_match($pattern, '/index.php'))->toBe(1)
        ->and(preg_match($pattern, '/wp-admin/admin-ajax.php'))->toBe(1);
});

it('does not hand PHP a request that only mentions .php in a filename', function () {
    $pattern = '#'.nginxPhpLocationPattern().'#';

    // The widened pattern must not match uploads that merely contain the string.
    expect(preg_match($pattern, '/uploads/notes.php.txt'))->toBe(0);
});

it('keeps the guard that a widened pattern depends on', function () {
    $source = file_get_contents(
        base_path('resources/views/server/vhosts/nginx/php.blade.php')
    );

    // The wider location is only safe because the distro snippet refuses to pass a script that
    // is not on disk; losing that include would open an execution hole.
    expect($source)->toContain('include snippets/fastcgi-php.conf;');
});
