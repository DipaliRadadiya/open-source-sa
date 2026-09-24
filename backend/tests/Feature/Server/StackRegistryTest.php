<?php

use App\Services\Server\Capabilities\ServerCapabilities;

/*
 * `install.sh` and the panel must agree on what a stack is.
 *
 * They did not. `--stack=docker` was added to the installer's own case arm and
 * not to `ServerCapabilities::STACKS`, so the install ran all the way through
 * package installation, composer, migrations and seeding — thirteen steps —
 * and then died on the last call it makes:
 *
 *     ERROR Unknown stack [docker]. Expected one of: lemp, lamp, ols, mern.
 *
 * A wasted server, and the failure arrives at the point of maximum sunk cost.
 *
 * This is the same duplication that produced every privilege bug this panel
 * has had, which `privilege.binaries` fixed by becoming the one list. The stack
 * list cannot be collapsed the same way — one side is bash, the other PHP — so
 * it gets a test that reads the installer instead.
 */

/**
 * The stacks `install.sh` will accept, read out of its own case arm.
 *
 * Parsed rather than hardcoded, because a hardcoded copy here would be a third
 * list and this test exists to object to the second.
 *
 * @return list<string>
 */
function installerStacks(): array
{
    $script = file_get_contents(base_path('../install.sh'));

    // The mapping arm: `lemp|mern|docker) WEB_SERVER="nginx" ;;`. Every stack
    // the installer honours appears here, because this is where it decides the
    // web server — a stack missing from it dies as "unknown stack" anyway.
    preg_match_all('/^\s*([a-z|]+)\)\s*WEB_SERVER=/m', $script, $matches);

    $stacks = [];

    foreach ($matches[1] as $arm) {
        foreach (explode('|', $arm) as $stack) {
            $stacks[] = $stack;
        }
    }

    // `ols` sets WEB_SERVER inside a multi-line arm rather than on the same
    // line, so it is matched separately — its absence here would be a false
    // pass, which is the one outcome this test must not produce.
    if (str_contains($script, 'ols)') && ! in_array('ols', $stacks, true)) {
        $stacks[] = 'ols';
    }

    return array_values(array_unique($stacks));
}

it('finds the stacks the installer accepts', function () {
    // Without this the comparison below passes on an empty list, which is how
    // a guard becomes decoration.
    expect(installerStacks())->not->toBeEmpty()
        ->and(installerStacks())->toContain('lemp');
});

it('accepts in the panel every stack the installer offers', function () {
    $missing = array_diff(installerStacks(), ServerCapabilities::stacks());

    expect($missing)->toBe([], implode("\n", array_merge(
        ['install.sh accepts these stacks but ServerCapabilities::STACKS does not,'],
        ['so `artisan server:record-stack` fails at the very end of the install:'],
        $missing,
    )));
});

it('offers in the installer every stack the panel accepts', function () {
    // The other direction, and it matters too: a stack the panel knows about
    // but the installer cannot build is a value only reachable by running the
    // artisan command by hand, which means it is untested on every real box.
    $unreachable = array_diff(ServerCapabilities::stacks(), installerStacks());

    expect($unreachable)->toBe([], implode("\n", array_merge(
        ['These stacks exist in the panel but install.sh cannot produce them:'],
        $unreachable,
    )));
});

it('gives every stack a web server the panel can actually drive', function () {
    // A preset naming a web server with no driver would record a server the
    // panel cannot write vhosts for — sites would be creatable and dead.
    $drivers = array_keys((array) config('server.web_server_drivers'));

    foreach (ServerCapabilities::stacks() as $stack) {
        $capabilities = app(ServerCapabilities::class);
        $reflection = new ReflectionClass($capabilities);
        $presets = $reflection->getConstant('STACKS');

        expect(in_array($presets[$stack]['web_server'], $drivers, true))->toBeTrue(
            "stack {$stack} maps to web server [{$presets[$stack]['web_server']}], which has no driver",
        );
    }
});
