<?php

use App\Services\Server\SudoersFile;

/*
 * The grant this renders is the one thing the panel writes that can lock an
 * operator out of their own server. So these assert the content rather than
 * the act of writing it — the write is a temp file, a `visudo -c` and a
 * rename, and none of that saves a file whose *content* is wrong.
 *
 * The regression they exist to prevent is not a malformed file, though. It is
 * the one that actually happened, six times: a binary the code elevates that
 * the grant does not cover, because the two lists were separate.
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

    // If this fails, a command ServerOps will prefix with sudo is not in the
    // grant — which does not degrade, it denies with "a password is required"
    // on a feature that looks configured.
    expect($missing)->toBe([], 'not granted: '.implode(', ', $missing));
});

it('grants nothing the panel does not elevate', function () {
    $allowed = array_merge(
        (array) config('server.privilege.binaries'),
        // php-fpm* is granted as a pattern and so is deliberately absent from
        // the binaries list; ServerOps matches it by prefix.
        ['php-fpm*'],
    );

    $extra = array_values(array_diff(
        array_map(fn (string $path): string => basename($path), sudoers()->entries()),
        $allowed,
    ));

    // The other direction matters too: a privilege granted for no reason is
    // root handed to an attacker who finds code execution, for a command
    // nothing calls.
    expect($extra)->toBe([], 'granted but unused: '.implode(', ', $extra));
});

it('uses the real location of binaries that are not in /usr/bin', function () {
    $entries = sudoers()->entries();

    // sudo matches on the resolved absolute path. /usr/bin/useradd does not
    // exist on Debian or Ubuntu, so a default-path guess here silently denies
    // every system-user operation the panel performs.
    expect($entries)->toContain('/usr/sbin/useradd')
        ->and($entries)->toContain('/usr/sbin/nginx')
        ->and($entries)->toContain('/usr/local/bin/wp')
        // Both spellings: /usr/sbin on Debian-family, /usr/bin on RHEL-family.
        // sudo ignores entries whose file is absent, so listing both is safe
        // and listing one breaks every asUser() call on half the distros.
        ->and($entries)->toContain('/usr/sbin/runuser')
        ->and($entries)->toContain('/usr/bin/runuser');
});

it('keeps php-fpm a wildcard rather than a version list', function () {
    // PoolManager runs `php-fpmX.Y -t` before every reload, and the installed
    // versions change through the panel's own feature. An exact list would
    // need editing every time a version is added.
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
        // Trailing newline: sudoers requires the last line terminated, and a
        // file that ends mid-line is a file visudo rejects.
        ->and($content)->toEndWith("\n");
});

it('sets no Defaults option, so a newer sudo cannot reject the whole file', function () {
    // `Defaults:panel !requiretty` shipped here until sudo removed the option
    // (gone by 1.9.17, which Ubuntu 26.04 ships). An unknown setting is a parse
    // error rather than a warning, so one inert line invalidated the entire
    // grant and every 26.04 install failed at `visudo -cqf`. This asserts the
    // absence rather than the old content: any Defaults entry is matched
    // against the target server's sudo, which is routinely newer than ours.
    expect(sudoers()->render())
        ->not->toContain('requiretty')
        ->not->toContain('Defaults');
});

it('writes beside the account the services run as', function () {
    expect(sudoers()->path())->toBe('/etc/sudoers.d/panel');

    // install.sh names the file from PANEL_SLUG and the account from the same
    // variable, so they agree by default — but an operator who set them apart
    // needs a way to say so, or the update rewrites the wrong file and the
    // real grant goes stale invisibly.
    config()->set('server.privilege.sudoers_file', '/etc/sudoers.d/custom');

    expect(sudoers()->path())->toBe('/etc/sudoers.d/custom');
});

it('renders a grant that install.sh no longer duplicates', function () {
    $installer = file_get_contents(base_path('../install.sh'));

    // The duplication is the defect. install.sh must ask the panel for this
    // list rather than carrying a second copy that has to be kept in step by
    // hand — which it never was: touch, certbot, openssl, crontab, stat and
    // mysqldump each broke a shipped feature by being added to only one side.
    expect($installer)->toContain('artisan panel:sudoers --print')
        ->and($installer)->not->toContain('local bins=(');
});
