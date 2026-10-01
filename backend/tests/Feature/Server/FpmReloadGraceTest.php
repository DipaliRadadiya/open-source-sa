<?php

use App\Services\Server\ManagedFile;
use App\Services\Server\Php\FpmReloadGrace;
use App\Services\Server\Php\Stacks\FpmPhpStack;
use App\Services\Server\Php\Stacks\LsphpPhpStack;
use App\Services\Server\Runtimes\PhpRuntime;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * A PHP-FPM reload kills every request in flight unless
 * process_control_timeout lets it wait — and the panel reloads on every site
 * change. Measured on the nginx test server 2026-10-01: 502 without the file,
 * 200 with it.
 */

function graceFor(string $stack): FpmReloadGrace
{
    return new FpmReloadGrace(app($stack), app(ManagedFile::class));
}

/** The commands run, in order, with tee's input alongside. */
function recordGraceCommands(int $configTestExit = 0): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $configTestExit) {
        $runs[] = ['command' => $process->command, 'input' => $process->input];

        if (str_contains(implode(' ', $process->command), 'php-fpm') && in_array('-t', $process->command, true)) {
            return Process::result(errorOutput: $configTestExit === 0 ? 'test is successful' : 'ERROR', exitCode: $configTestExit);
        }

        return Process::result(exitCode: 0);
    });

    return $runs;
}

function graceCommandIndex(ArrayObject $runs, callable $match): ?int
{
    foreach ($runs as $i => $run) {
        if ($match($run['command'])) {
            return $i;
        }
    }

    return null;
}

it('writes the timeout for a version, tests the config, then reloads', function () {
    $runs = recordGraceCommands();

    expect(graceFor(FpmPhpStack::class)->apply('8.3'))->toBeTrue();

    $write = graceCommandIndex($runs, fn ($c) => $c === ['tee', '/etc/php/8.3/fpm/pool.d/00.panel-global.conf']);
    $test = graceCommandIndex($runs, fn ($c) => in_array('-t', $c, true));
    $reload = graceCommandIndex($runs, fn ($c) => $c === ['systemctl', 'reload', 'php8.3-fpm']);

    expect($write)->not->toBeNull()
        ->and($runs[$write]['input'])->toContain("[global]\nprocess_control_timeout = 30s\n")
        ->and($test)->toBeGreaterThan($write)
        ->and($reload)->toBeGreaterThan($test);
});

it('takes the file out and reloads nothing when FPM refuses it', function () {
    $runs = recordGraceCommands(configTestExit: 1);

    expect(graceFor(FpmPhpStack::class)->apply('8.3'))->toBeFalse();

    expect(graceCommandIndex($runs, fn ($c) => $c === ['rm', '-f', '/etc/php/8.3/fpm/pool.d/00.panel-global.conf']))->not->toBeNull()
        ->and(graceCommandIndex($runs, fn ($c) => ($c[0] ?? null) === 'systemctl'))->toBeNull();
});

it('does nothing on OpenLiteSpeed, which has no FPM and restarts LSPHP gracefully', function () {
    $runs = recordGraceCommands();

    expect(graceFor(LsphpPhpStack::class)->apply('8.3'))->toBeFalse()
        ->and($runs->count())->toBe(0);
});

it('can never be mistaken for a site pool', function () {
    // A site's pool is named after its slug, and a slug has no dots.
    $name = basename(FpmReloadGrace::FILE, '.conf');

    expect(Str::slug($name))->not->toBe($name);
});

it('is written when the panel installs a PHP version', function () {
    $runs = recordGraceCommands();

    app(PhpRuntime::class)->install('8.3');

    expect(graceCommandIndex($runs, fn ($c) => $c === ['tee', '/etc/php/8.3/fpm/pool.d/00.panel-global.conf']))->not->toBeNull();
});

it('is the same file install.sh writes for the first version', function () {
    $installer = installerSource();

    expect($installer)->toContain('/fpm/pool.d/'.FpmReloadGrace::FILE.'"')
        ->and($installer)->toContain("<<'GRACE'\n".FpmReloadGrace::contents().'GRACE');
});
