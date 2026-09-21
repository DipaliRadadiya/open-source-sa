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
    $source = installerSource();

    $function = substr(
        $source,
        strpos($source, 'add_php_repository() {'),
        strpos($source, 'php_candidate() {') - strpos($source, 'add_php_repository() {'),
    );

    $compgenAt = strpos($function, 'compgen -G "/etc/apt/sources.list.d/ondrej*php*"');

    expect($compgenAt)->not->toBeFalse();

    // Measured against the `fi` that closes that branch, not against the `if`.
    //
    // The first version of this test compared the refresh to the `compgen`
    // line and passed under the very regression it was written for — the
    // refresh sits after `compgen` whether it is inside the branch or outside
    // it. Only "after the branch ends" distinguishes the two.
    $closingFi = strpos($function, "\n    fi\n", $compgenAt);
    $refreshAt = strrpos($function, 'Refreshing package lists from the PHP repository');

    expect($closingFi)->not->toBeFalse()
        ->and($refreshAt)->not->toBeFalse()
        ->and($refreshAt)->toBeGreaterThan($closingFi);
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
