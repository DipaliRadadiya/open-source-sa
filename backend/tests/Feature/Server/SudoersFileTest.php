<?php

use App\Services\Server\SudoersFile;

/*
 * The grant this renders can lock an operator out of their own server, so these assert its
 * content rather than the act of writing it (temp file, `visudo -c`, rename).
 *
 * The main regression: a binary the code elevates that the grant does not cover, because the
 * two lists were separate.
 */

function sudoers(): SudoersFile
{
    return new SudoersFile;
}

it('grants every binary the panel elevates', function () {
    $entries = sudoers()->entries();

    $granted = array_map(fn (string $path): string => basename($path), $entries);

    $missing = array_values(array_diff(
        (array) config('server.privilege.binaries'),
        $granted,
    ));

    // If this fails, a command ServerOps prefixes with sudo is not in the grant: it denies with
    // "a password is required" on a feature that looks configured.
    expect($missing)->toBe([], 'not granted: '.implode(', ', $missing));
});

it('grants nothing the panel does not elevate', function () {
    $allowed = array_merge(
        (array) config('server.privilege.binaries'),
        // php-fpm* is granted as a pattern, so it is absent from the binaries list; ServerOps
        // matches it by prefix.
        ['php-fpm*'],
    );

    $extra = array_values(array_diff(
        array_map(fn (string $path): string => basename($path), sudoers()->entries()),
        $allowed,
    ));

    // The other direction matters too: a privilege nothing uses is root handed to an attacker who
    // finds code execution.
    expect($extra)->toBe([], 'granted but unused: '.implode(', ', $extra));
});

it('uses the real location of binaries that are not in /usr/bin', function () {
    $entries = sudoers()->entries();

    // sudo matches on the resolved absolute path. /usr/bin/useradd does not exist on Debian or
    // Ubuntu, so a default-path guess silently denies every system-user operation.
    expect($entries)->toContain('/usr/sbin/useradd')
        ->and($entries)->toContain('/usr/sbin/nginx')
        ->and($entries)->toContain('/usr/local/bin/wp')
        // Both spellings: /usr/sbin on Debian-family, /usr/bin on RHEL-family. sudo ignores
        // entries whose file is absent, so listing both is safe; one breaks asUser() on half the distros.
        ->and($entries)->toContain('/usr/sbin/runuser')
        ->and($entries)->toContain('/usr/bin/runuser');
});

it('keeps php-fpm a wildcard rather than a version list', function () {
    // PoolManager runs `php-fpmX.Y -t` before every reload and the installed versions change, so an
    // exact list would need editing for every new version.
    expect(sudoers()->entries())->toContain('/usr/sbin/php-fpm*');
});

it('renders one grant line the panel account can be matched on', function () {
    $content = sudoers()->render();

    $lines = array_values(array_filter(
        explode("\n", $content),
        fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toStartWith('panel ALL=(root) NOPASSWD: /')
        // Trailing newline: sudoers requires the last line terminated; visudo rejects a file that
        // ends mid-line.
        ->and($content)->toEndWith("\n");
});

it('sets no Defaults option, so a newer sudo cannot reject the whole file', function () {
    // `Defaults:panel !requiretty` is gone: sudo removed the option (by 1.9.17), and an unknown
    // setting is a parse error that invalidates the whole grant. Any Defaults entry is checked
    // against the target server's sudo, which is routinely newer than ours.
    expect(sudoers()->render())
        ->not->toContain('requiretty')
        ->not->toContain('Defaults');
});

it('writes beside the account the services run as', function () {
    expect(sudoers()->path())->toBe('/etc/sudoers.d/panel');

    // install.sh names the file and the account from PANEL_SLUG, so they agree by default; an
    // operator who set them apart needs a way to say so, or the update rewrites the wrong file.
    config()->set('server.privilege.sudoers_file', '/etc/sudoers.d/custom');

    expect(sudoers()->path())->toBe('/etc/sudoers.d/custom');
});

it('renders a grant that install.sh no longer duplicates', function () {
    $installer = file_get_contents(base_path('../install.sh'));

    // The duplication is the defect: install.sh must ask the panel for this list rather than
    // carry a second copy kept in step by hand.
    expect($installer)->toContain('artisan panel:sudoers --print')
        ->and($installer)->not->toContain('local bins=(');
});
