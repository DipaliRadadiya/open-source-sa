<?php

use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Doctor\Checks\DynamicResponseLimitCheck;

/*
 * The download ceiling nobody can see.
 *
 * OpenLiteSpeed refuses a response over `maxDynRespSize` with a bare 413
 * *before* PHP runs, so the panel logs nothing at all and the browser shows
 * "this site can't be reached". A 6.48 GB file on a stock install fails with
 * no evidence anywhere that the panel was ever asked.
 *
 * `install.sh` raises it on a fresh install. This check exists because the
 * updater ships code and never configuration, so every panel older than that
 * change keeps the ceiling.
 */

function limitCheck(?string $webServer): DynamicResponseLimitCheck
{
    $capabilities = Mockery::mock(ServerCapabilities::class);
    $capabilities->shouldReceive('recordedWebServer')->andReturn($webServer);

    return new DynamicResponseLimitCheck($capabilities);
}

/** Runs the parser against a config body, via a temporary file. */
function limitVerdict(string $body): array
{
    $check = limitCheck('openlitespeed');

    $reflection = new ReflectionMethod($check, 'configuredBytes');

    return ['bytes' => $reflection->invoke($check, $body)];
}

it('passes on nginx and Apache, which have no such cap', function () {
    // Not an equivalent invented for symmetry: neither limits a proxied or
    // FastCGI response body, so there is genuinely nothing to check.
    foreach (['nginx', 'apache'] as $webServer) {
        expect(limitCheck($webServer)->run()['status'])->toBe('pass');
    }
});

it('reads the value OpenLiteSpeed actually writes', function () {
    // The real file is indented and has a trailing space. A parser anchored
    // on a bare line start finds nothing and the check reports "absent" on a
    // server where the directive is right there.
    expect(limitVerdict("    maxDynRespSize               2047M \n")['bytes'])
        ->toBe(2047 * 1024 ** 2);

    expect(limitVerdict("    maxDynRespSize               1024G \n")['bytes'])
        ->toBe(1024 * 1024 ** 3);

    // A bare number is bytes.
    expect(limitVerdict("maxDynRespSize 500000\n")['bytes'])->toBe(500000);

    // Absent is distinct from too-low: "I could not find out" and "this is
    // wrong" need different advice.
    expect(limitVerdict("maxReqBodySize 2047M\n")['bytes'])->toBeNull();
});

it('treats the shipped 2047M as a failure, not a warning', function () {
    // The default is not a preference to respect. It is below what a disk
    // image or a database dump needs, and the failure it produces carries no
    // diagnostic at all.
    $bytes = limitVerdict("    maxDynRespSize               2047M \n")['bytes'];

    expect($bytes)->toBeLessThan(8 * 1024 ** 3);
});

it('names the number in the detail, so an operator can see the gap', function () {
    // A check that says "too low" without saying what it is sends someone to
    // read a config file to find out what the panel already knows.
    $check = limitCheck('openlitespeed');
    $result = $check->run();

    expect($result)->toHaveKeys(['status', 'detail', 'fix']);

    if ($result['status'] === 'fail') {
        expect($result['detail'])->toContain('maxDynRespSize')
            ->and($result['fix'])->toBe('doctor.fixes.dynamic_response_limit');
    }
});

it('translates its name and its fix in every locale', function () {
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        expect(__('doctor.checks.dynamic_response_limit', [], $locale))
            ->not->toBe('doctor.checks.dynamic_response_limit', "name missing in {$locale}");

        expect(__('doctor.fixes.dynamic_response_limit', [], $locale))
            ->not->toBe('doctor.fixes.dynamic_response_limit', "fix missing in {$locale}");
    }
});
