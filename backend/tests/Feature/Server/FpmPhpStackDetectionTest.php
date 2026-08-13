<?php

use App\Services\Server\Php\Stacks\FpmPhpStack;
use Illuminate\Support\Facades\File;

/*
 * Which PHP versions the panel believes are installed.
 *
 * This used to be "whatever has a config directory", which is not the same
 * question. `apt remove php8.5-fpm` leaves /etc/php/8.5/fpm behind — its
 * contents are conffiles, and only `purge` removes those — and the panel's own
 * pool files under pool.d/ are not dpkg's to delete at all, so the directory
 * outlives the package for good. A removed version stayed on the PHP screen
 * offering settings for a runtime that no longer existed, and reloads of a
 * service that could not start.
 */

beforeEach(function () {
    $this->phpDir = sys_get_temp_dir().'/sv-oss-fpm-detect-'.getmypid();
    $this->binDir = sys_get_temp_dir().'/sv-oss-fpm-bin-'.getmypid();

    File::deleteDirectory($this->phpDir);
    File::deleteDirectory($this->binDir);
    File::makeDirectory($this->binDir, 0755, true);

    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->phpDir}/{$version}/fpm", 0755, true);
    }

    config([
        'server.php_dir' => $this->phpDir,
        'server.php_fpm_binary_pattern' => $this->binDir.'/php-fpm{version}',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
    File::deleteDirectory($this->binDir);
});

it('lists a version whose FPM binary is present', function () {
    File::put("{$this->binDir}/php-fpm8.3", '');
    File::put("{$this->binDir}/php-fpm8.4", '');

    expect(app(FpmPhpStack::class)->versions())->toBe(['8.4', '8.3']);
});

it('drops a version whose config directory outlived its package', function () {
    // 8.3 removed: apt took the binary, /etc/php/8.3/fpm stayed.
    File::put("{$this->binDir}/php-fpm8.4", '');

    expect(app(FpmPhpStack::class)->versions())->toBe(['8.4'])
        ->and(app(FpmPhpStack::class)->installed('8.3'))->toBeFalse();
});

it('reports nothing installed when every binary is gone', function () {
    // Not an error state — a box mid-reinstall looks exactly like this, and
    // claiming versions are present would have the panel try to reload them.
    expect(app(FpmPhpStack::class)->versions())->toBe([]);
});

it('still orders newest first', function () {
    File::makeDirectory("{$this->phpDir}/8.10/fpm", 0755, true);

    foreach (['8.3', '8.4', '8.10'] as $version) {
        File::put("{$this->binDir}/php-fpm{$version}", '');
    }

    // Version compare, not string sort: 8.10 is newer than 8.4 and sorts
    // before it only if the comparison understands that.
    expect(app(FpmPhpStack::class)->versions())->toBe(['8.10', '8.4', '8.3']);
});
