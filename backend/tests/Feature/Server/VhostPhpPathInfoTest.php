<?php

/**
 * The PHP location must reach scripts addressed with a path appended.
 *
 * Reported as "Moodle installs fine, then the domain shows an unstyled page".
 * Moodle uses slash arguments: every stylesheet and script is requested as
 * `/theme/styles.php/boost/<rev>/all`. Against `location ~ \.php$` — anchored
 * at the end — that URL does not match the PHP block at all. It falls through
 * to `location /`, `try_files` hands it to index.php, and the browser gets
 * HTML where it asked for CSS.
 *
 * Asserted against the location's own regex rather than a live nginx, because
 * the question is purely whether the pattern matches the URL, and nginx
 * compiles these with PCRE — the same engine running this test.
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
    // Without this the assertions below run against an empty pattern and pass
    // for the wrong reason.
    expect(nginxPhpLocationPattern())->not->toBe('');
});

it('routes path-info script URLs to PHP, not to index.php', function () {
    $pattern = '#'.nginxPhpLocationPattern().'#';

    // Moodle's slash arguments — the reported failure. Also how several other
    // applications address their own entry points.
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

    // Widening the pattern must not start matching uploads that merely contain
    // the string. `.php.txt` is not a PHP request and never was.
    expect(preg_match($pattern, '/uploads/notes.php.txt'))->toBe(0);
});

it('keeps the guard that a widened pattern depends on', function () {
    $source = file_get_contents(
        base_path('resources/views/server/vhosts/nginx/php.blade.php')
    );

    // Matching more URLs is only safe because the distro snippet refuses to
    // pass a script that does not exist on disk. Losing that include while
    // keeping the wider location would turn this into an execution hole, so
    // the two are pinned together.
    expect($source)->toContain('include snippets/fastcgi-php.conf;');
});
