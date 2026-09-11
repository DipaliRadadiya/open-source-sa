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
