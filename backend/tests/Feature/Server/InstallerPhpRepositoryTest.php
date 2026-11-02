<?php

/*
 * The installer must decide PHP is available by asking apt, not by looking for
 * a filename.
 *
 * A user's nginx install on Ubuntu 24.04 died with:
 *
 *   PHP 8.4 is not available from apt on Ubuntu 24.04.4 LTS.
 *   The PHP repository was added but carries no php8.4 for noble.
 *
 * Both sentences were wrong. ondrej publishes php8.4 for noble — verified
 * against the live index, `php8.4-fpm 8.4.25-1+ubuntu24.04.1+deb.sury.org+1`.
 * What had actually happened is that apt never fetched that index: the old
 * flow decided the repository was configured because a file matching
 * `ondrej*php*` existed, and skipped refreshing it.
 *
 * Two ways to get there, and the installer could not tell them apart:
 *   - a previous run interrupted after `add-apt-repository` wrote the file
 *   - a refresh that failed to download — `apt-get update` exits **0** on an
 *     unreachable repository, printing only `W: Failed to fetch`
 *
 * A source scan rather than a live install, because a real reproduction needs
 * a fresh Ubuntu box in a broken apt state. These assertions pin the shape of
 * the fix; they do not prove an install succeeds.
 */
it('decides PHP availability by asking apt, not by matching a filename', function () {
    $source = installerSource();

    // The repository is added only when apt cannot already resolve the package.
    expect($source)->toContain('ensure_php_available')
        ->and($source)->toContain('php_candidate');

    // The old entry point is gone rather than left beside the new one — two
    // functions answering "is PHP available" is how the first one drifted.
    expect($source)->not->toContain('assert_php_available');
});

it('refreshes the PHP repository even when its sources file already exists', function () {
    // The specific regression. `add-apt-repository` writes the file before the
    // download succeeds, so "the file exists" never meant "apt has the index".
    // Skipping the refresh on that basis is what produced a configured
    // repository with no packages behind it.
    //
    // Since 2026-11-02 the refresh is not add_php_repository's own: every
    // source is written first and install_packages refreshes them all at once.
    // So the guard is now that the one refresh comes after the sources and is
    // not inside any condition — it runs whether the file was just written or
    // was already there — and that PHP is asked of apt only after it.
    $add = installerFn('add_php_repository');

    expect($add)->toContain('compgen -G "/etc/apt/sources.list.d/ondrej*php*"')
        ->and(preg_match('/apt-get[^\n]*update/', $add))->toBe(0);

    $packages = installerFn('install_packages');
    $sources = strpos($packages, "\n    add_package_sources\n");
    // Four spaces: at the function's own level, not inside an if.
    $refresh = strpos($packages, "\n    run_apt \"Refreshing package lists\" apt-get update -qq\n");
    $asked = strpos($packages, 'ensure_php_available');

    expect($sources)->not->toBeFalse()
        ->and($refresh)->not->toBeFalse()
        ->and($sources)->toBeLessThan($refresh)
        ->and($refresh)->toBeLessThan($asked);

    expect(installerFn('add_package_sources'))->toContain('add_php_repository');
});

it('does not tell the user the repository lacks the package', function () {
    // It has it. Saying otherwise sends someone to wait for a publication that
    // already happened, and the real causes — a failed download, a stale
    // source for another release — go unconsidered.
    $source = installerSource();

    // Scoped to the `die` text, not the whole file: the comment above the
    // function quotes the old wording to explain why it was wrong, and a
    // whole-file scan cannot tell an explanation from a claim. That false
    // positive is why this reads the message rather than the source.
    $message = substr(
        $source,
        strpos($source, 'die "apt cannot install PHP'),
        strpos($source, 'Nothing further has been installed."') - strpos($source, 'die "apt cannot install PHP'),
    );

    expect($message)->not->toContain('carries no php')
        ->and($message)->toContain('the repository index failed to download')
        // Names the stale-source case too, which the old message never
        // considered and which no amount of waiting for ondrej would fix.
        ->and($message)->toContain('different Ubuntu release');
});
