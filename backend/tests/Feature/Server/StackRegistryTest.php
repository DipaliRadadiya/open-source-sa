<?php

use App\Services\Server\Capabilities\ServerCapabilities;

/*
 * `install.sh` and the panel must agree on what a stack is.
 *
 * A stack added only to the installer's case arm lets the install run to the last step and then
 * die with `ERROR Unknown stack [docker]. Expected one of: lemp, lamp, ols, mern.`
 *
 * The two lists cannot be collapsed (one side is bash, the other PHP), so this test reads the
 * installer instead.
 */

/**
 * The stacks `install.sh` will accept, read out of its own case arm.
 *
 * Parsed rather than hardcoded: a hardcoded copy would be a third list.
 *
 * @return list<string>
 */
function installerStacks(): array
{
    $script = file_get_contents(base_path('../install.sh'));

    // The mapping arm: `lemp|mern|docker) WEB_SERVER="nginx" ;;`. Every stack the installer
    // honours appears here, since this is where it picks the web server.
    preg_match_all('/^\s*([a-z|]+)\)\s*WEB_SERVER=/m', $script, $matches);

    $stacks = [];

    foreach ($matches[1] as $arm) {
        foreach (explode('|', $arm) as $stack) {
            $stacks[] = $stack;
        }
    }

    // `ols` sets WEB_SERVER inside a multi-line arm, so it is matched separately; missing it
    // would be a false pass.
    if (str_contains($script, 'ols)') && ! in_array('ols', $stacks, true)) {
        $stacks[] = 'ols';
    }

    return array_values(array_unique($stacks));
}

it('finds the stacks the installer accepts', function () {
    // Without this the comparison below passes on an empty list.
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
    // The other direction: a stack the installer cannot build is only reachable by running the
    // artisan command by hand, so it is untested on real servers.
    $unreachable = array_diff(ServerCapabilities::stacks(), installerStacks());

    expect($unreachable)->toBe([], implode("\n", array_merge(
        ['These stacks exist in the panel but install.sh cannot produce them:'],
        $unreachable,
    )));
});

it('gives every stack a web server the panel can actually drive', function () {
    // A preset naming a web server with no driver would let sites be created that cannot get a vhost.
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
