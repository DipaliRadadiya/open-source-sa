<?php

/*
 * The installer must decide PHP is available by asking apt, not by looking for a filename.
 *
 * A repository file matching `ondrej*php*` does not mean apt has the index, and the installer
 * must tell these apart:
 *   - a previous run interrupted after `add-apt-repository` wrote the file
 *   - a refresh that failed to download — `apt-get update` exits **0** on an unreachable
 *     repository, printing only `W: Failed to fetch`
 *
 * A source scan rather than a live install, because a real reproduction needs a fresh Ubuntu box
 * in a broken apt state. These assertions pin the shape of the fix; they do not prove an install
 * succeeds.
 */
it('decides PHP availability by asking apt, not by matching a filename', function () {
    $source = installerSource();

    // The repository is added only when apt cannot already resolve the package.
    expect($source)->toContain('ensure_php_available')
        ->and($source)->toContain('php_candidate');

    // The old entry point is gone rather than left beside the new one.
    expect($source)->not->toContain('assert_php_available');
});

it('refreshes the PHP repository even when its sources file already exists', function () {
    // `add-apt-repository` writes the file before the download succeeds, so "the file exists"
    // never meant "apt has the index". Skipping the refresh on that basis leaves a configured
    // repository with no packages behind it.
    //
    // The refresh belongs to install_packages, not add_php_repository: the guard is that the one
    // refresh comes after the sources, is not inside any condition, and PHP is asked of apt only
    // after it.
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
    // It has it. Saying otherwise hides the real causes — a failed download, a stale source for
    // another release.
    $source = installerSource();

    // Scoped to the `die` text, not the whole file: a comment above the function may quote the
    // old wording, and a whole-file scan cannot tell an explanation from a claim.
    $message = substr(
        $source,
        strpos($source, 'die "apt cannot install PHP'),
        strpos($source, 'Nothing further has been installed."') - strpos($source, 'die "apt cannot install PHP'),
    );

    expect($message)->not->toContain('carries no php')
        ->and($message)->toContain('the repository index failed to download')
        // Names the stale-source case too, which waiting for ondrej would not fix.
        ->and($message)->toContain('different Ubuntu release');
});
