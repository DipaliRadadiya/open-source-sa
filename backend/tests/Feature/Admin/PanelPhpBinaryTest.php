<?php

use App\Services\Panel\PanelPhpBinary;
use Illuminate\Support\Facades\File;

/*
 * The panel's self-update called `/usr/bin/php{version}`, which does not exist
 * on an OpenLiteSpeed server — that stack installs no ondrej PHP and runs the
 * panel on LSPHP under /usr/local/lsws. Every OLS install was therefore unable
 * to update itself, which means unable to receive a security fix.
 *
 * These tests are mostly about the ORDER of the fallbacks, because getting it
 * backwards fixes OLS and quietly breaks the two stacks that already worked.
 * Both candidate paths are injected so that order is exercised for real rather
 * than asserted against whatever the machine running the suite happens to have.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sv-oss-php-bin-'.getmypid();
    File::deleteDirectory($this->dir);
    File::makeDirectory($this->dir, 0755, true);

    $this->makeExecutable = function (string $name): string {
        $path = $this->dir.'/'.$name;
        File::put($path, "#!/bin/sh\n");
        chmod($path, 0755);

        return $path;
    };
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('uses the configured binary above everything else', function () {
    config(['panel_update.php_binary' => '/usr/local/lsws/lsphp84/bin/php']);

    expect(app(PanelPhpBinary::class)->path())->toBe('/usr/local/lsws/lsphp84/bin/php');
});

it('ignores an empty or whitespace configured value rather than returning it', function (string $value) {
    // env() hands back '' for a key that is present but unset, and an empty
    // binary path would be executed as the empty string — a failure naming
    // nothing at all.
    config(['panel_update.php_binary' => $value, 'panel_update.php_version' => '8.4']);

    expect(app(PanelPhpBinary::class)->path())->toStartWith('/');
})->with(['', '   ']);

it('prefers the versioned path when BOTH exist, so nginx and Apache do not move', function () {
    // THE REGRESSION GUARD, and the reason the order is not the shorter rule.
    // /usr/local/bin/php is not ours on those stacks — nothing in install.sh
    // creates it there, so it is whatever the operator put in, plausibly a
    // different major version than the panel needs.
    $versioned = ($this->makeExecutable)('php8.4');
    $fallback = ($this->makeExecutable)('php');

    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    $resolver = new PanelPhpBinary($this->dir.'/php', $fallback);

    expect($resolver->path())->toBe($versioned)
        ->and($resolver->path())->not->toBe($fallback);
});

it('falls back to the LSPHP symlink when there is no versioned binary', function () {
    // The OpenLiteSpeed case: no ondrej PHP anywhere, and /usr/local/bin/php
    // is the symlink install.sh points at LSPHP's CLI.
    $fallback = ($this->makeExecutable)('php');

    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    $resolver = new PanelPhpBinary($this->dir.'/does-not-exist-php', $fallback);

    expect($resolver->path())->toBe($fallback);
});

it('returns the versioned path when nothing exists, rather than throwing', function () {
    // Something is wrong that this class cannot name, and "no such file:
    // /usr/bin/php8.4" tells a human more than an exception invented here.
    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    $resolver = new PanelPhpBinary($this->dir.'/missing-php', $this->dir.'/missing-fallback');

    expect($resolver->path())->toBe($this->dir.'/missing-php8.4');
});

it('does not treat a non-executable file as a usable interpreter', function () {
    // A path that exists but cannot be run is not an interpreter. is_executable
    // rather than file_exists is what makes the fallback reachable on a box
    // where something left a stub behind.
    File::put($this->dir.'/php8.4', 'not executable');
    chmod($this->dir.'/php8.4', 0644);
    $fallback = ($this->makeExecutable)('php');

    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    expect((new PanelPhpBinary($this->dir.'/php', $fallback))->path())->toBe($fallback);
});

it('names the real LSPHP symlink as its fallback', function () {
    // The constant is the whole point on a real server; a test that only ever
    // saw injected temp paths would not notice it being changed.
    expect(PanelPhpBinary::FALLBACK)->toBe('/usr/local/bin/php');
});

/*
 * The PHP screen's "make default" button now owns /usr/local/bin/php on the
 * LSPHP stack, because that symlink — not the alternatives group — is what
 * `php` resolves through on an OLS box. Measured on a real one.
 *
 * Which makes the order below load-bearing in a new way: if the panel still
 * resolved its own interpreter through that symlink, changing the CLI default
 * would change the PHP the panel updates itself with, up to and including a
 * version that cannot run it.
 */

it('prefers the interpreter it is running under over the shared symlink', function () {
    // Both exist, as they do on an OLS box after a default change. The tree the
    // panel is executing from is the answer; the shared symlink is now a user
    // setting.
    $tree = $this->dir.'/lsphp84/bin';
    File::makeDirectory($tree, 0755, true);
    File::put($tree.'/lsphp', "#!/bin/sh\n");
    File::put($tree.'/php', "#!/bin/sh\n");
    chmod($tree.'/php', 0755);

    $fallback = ($this->makeExecutable)('shared-php');

    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    $resolver = new PanelPhpBinary(
        $this->dir.'/does-not-exist-php',
        $fallback,
        // What PHP_BINARY is under LSAPI.
        $tree.'/lsphp',
    );

    expect($resolver->path())->toBe($tree.'/php')
        ->and($resolver->path())->not->toBe($fallback);
});

it('refuses the two shared paths as a sibling, whatever is running', function () {
    // Reaching either here would reintroduce exactly the coupling this breaks:
    // both are links somebody else may move. `/usr/bin/php` is the alternatives
    // group the button sets; `/usr/local/bin/php` is the PATH winner it moves.
    $fallback = ($this->makeExecutable)('fallback-php');

    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    foreach (['/usr/bin/php-fpm8.4', '/usr/local/bin/php-something'] as $running) {
        $resolver = new PanelPhpBinary($this->dir.'/does-not-exist-php', $fallback, $running);

        // dirname() of those is /usr/bin and /usr/local/bin, so the candidate
        // would be exactly the path that must not be used.
        expect($resolver->path())->toBe($fallback);
    }
});

it('still prefers the versioned path over the running interpreter', function () {
    // nginx and Apache must resolve exactly as they did before any of this:
    // there, /usr/bin/php8.4 is the panel's own and is checked first.
    $versioned = ($this->makeExecutable)('php8.4');

    $tree = $this->dir.'/tree';
    File::makeDirectory($tree, 0755, true);
    File::put($tree.'/php', "#!/bin/sh\n");
    chmod($tree.'/php', 0755);

    config(['panel_update.php_binary' => '', 'panel_update.php_version' => '8.4']);

    $resolver = new PanelPhpBinary($this->dir.'/php', ($this->makeExecutable)('shared'), $tree.'/php');

    expect($resolver->path())->toBe($versioned);
});
