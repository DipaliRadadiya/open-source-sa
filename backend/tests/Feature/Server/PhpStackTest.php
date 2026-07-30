<?php

use App\Contracts\PhpStack;
use App\Models\ServerCapability;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\Stacks\FpmPhpStack;
use Illuminate\Support\Facades\File;

/**
 * Characterisation tests for the PHP stack abstraction.
 *
 * The refactor that introduced `PhpStack` moved a pile of FPM facts out of
 * seven services and into one place. These assert the moved values are the
 * values that used to be spelled out at the call sites — so the refactor is
 * provably identity on an FPM box, and a later LSPHP driver has something to
 * differ from.
 */
beforeEach(function () {
    // A fake /etc/php with two installed versions, so the assertions never
    // depend on what this machine happens to have.
    $this->phpDir = sys_get_temp_dir().'/sv-oss-stack-'.getmypid();
    File::deleteDirectory($this->phpDir);
    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->phpDir}/{$version}/fpm", 0755, true);
    }

    config(['server.php_dir' => $this->phpDir]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

function stackFor(?string $webServer): PhpStack
{
    ServerCapability::query()->delete();

    if ($webServer !== null) {
        ServerCapability::query()->create([
            'stack' => 'lemp',
            'web_server' => $webServer,
            'capabilities' => [],
            'source' => 'detected',
            'verified_at' => now(),
        ]);
    }

    // A fresh manager each time: `current()` memoises per instance.
    return (new PhpStackManager(app(ServerCapabilities::class)))->stack();
}

it('resolves FPM for every web server that talks to FPM', function (string $webServer) {
    expect(stackFor($webServer))->toBeInstanceOf(FpmPhpStack::class);
})->with(['nginx', 'apache']);

it('falls back to FPM for an unrecognised web server', function () {
    // Not an error: a panel that refuses to describe PHP because it cannot
    // name the web server is worse than one that assumes the common answer.
    expect(stackFor('caddy'))->toBeInstanceOf(FpmPhpStack::class);
});

it('reports its key so callers can branch without checking the class', function () {
    expect(app(PhpStack::class)->key())->toBe('fpm');
});

describe('the FPM facts it now owns', function () {
    beforeEach(function () {
        $this->stack = app(FpmPhpStack::class);
    });

    it('detects versions from the fpm directories, newest first', function () {
        expect($this->stack->versions())->toBe(['8.4', '8.3'])
            ->and($this->stack->installed('8.4'))->toBeTrue()
            ->and($this->stack->installed('7.4'))->toBeFalse();
    });

    it('reports no versions when the php directory is absent', function () {
        config(['server.php_dir' => $this->phpDir.'-gone']);

        expect($this->stack->versions())->toBe([]);
    });

    it('points at the paths the services used to build themselves', function () {
        expect($this->stack->iniPath('8.4'))->toBe($this->phpDir.'/8.4/fpm/php.ini')
            ->and($this->stack->sapiDir('8.4', 'fpm'))->toBe($this->phpDir.'/8.4/fpm')
            ->and($this->stack->modsDir('8.4'))->toBe($this->phpDir.'/8.4/mods-available')
            ->and($this->stack->binaryPath('8.4'))->toBe('/usr/bin/php8.4')
            ->and($this->stack->logPath('8.4'))->toBe('/var/log/php8.4-fpm.log');
    });

    it('names the per-version unit, and maps a unit back to its version', function () {
        expect($this->stack->serviceName('8.4'))->toBe('php8.4-fpm')
            ->and($this->stack->versionForService('php8.4-fpm'))->toBe('8.4')
            // Not ours, and not an installed version: both are null rather
            // than a guess parsed out of the string.
            ->and($this->stack->versionForService('nginx'))->toBeNull()
            ->and($this->stack->versionForService('php7.4-fpm'))->toBeNull();
    });

    it('validates a version with that version own binary', function () {
        expect($this->stack->configTestCommand('8.4'))->toBe(['/usr/sbin/php-fpm8.4', '-t']);
    });

    it('builds package names the way apt spells them', function () {
        config(['server.runtimes.php.base_packages' => ['fpm', 'cli', 'common']]);

        expect($this->stack->packagePrefix('8.4'))->toBe('php8.4-')
            ->and($this->stack->extensionPackage('8.4', 'mysql'))->toBe('php8.4-mysql')
            ->and($this->stack->versionPackages('8.4'))->toBe(['php8.4-fpm', 'php8.4-cli', 'php8.4-common'])
            ->and($this->stack->sapis('8.4'))->toBe(['cli', 'fpm']);
    });

    it('prefixes every configured base package, not just the first few', function () {
        // The real default installs a usable version, not a bare interpreter.
        expect($this->stack->versionPackages('8.4'))
            ->toHaveCount(count((array) config('server.runtimes.php.base_packages')))
            ->each->toStartWith('php8.4-');
    });

    it('finds every installable version, and reads the number back out', function () {
        expect($this->stack->installablePattern())->toBe('^php[0-9]+\.[0-9]+-fpm$');

        preg_match_all($this->stack->installableMatcher(), "php8.4-fpm - server\nphp8.3-fpm - server\nphp8.4-cli - cli\n", $matches);

        // The -cli line must not match: only a version that ships an FPM
        // package is one this stack can install.
        expect($matches[1])->toBe(['8.4', '8.3']);
    });
});
