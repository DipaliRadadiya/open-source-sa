<?php

/**
 * install.sh on a server with no route to the internet.
 *
 * `ip -4 route get` exits 2 ("Network is unreachable"). Under the installer's
 * `set -Eeuo pipefail` and ERR trap, that used to fire the trap inside
 * resolve_hostnames() before its own "could not determine this server's IP"
 * message could run, so the user got a raw trap report, printed twice.
 */

/** local_route_ip() under the installer's shell options, with `ip` replaced. */
function localRouteIpRun(string $ipBody): array
{
    $script = 'set -Eeuo pipefail
        trap '.escapeshellarg('echo "ERR-TRAP line $LINENO: $BASH_COMMAND"').' ERR
        ip() { '.$ipBody.'; }
        '.installerFunctions('local_route_ip').'
        result=$(local_route_ip)
        echo "result=[${result}]"';

    $out = [];
    $code = 0;
    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

it('returns nothing, without tripping the ERR trap, when there is no route', function () {
    $run = localRouteIpRun('echo "RTNETLINK answers: Network is unreachable" >&2; return 2');

    expect($run['code'])->toBe(0)
        ->and($run['out'])->not->toContain('ERR-TRAP')
        ->and($run['out'])->toContain('result=[]');
});

it('still returns the source address when there is a route', function () {
    $run = localRouteIpRun('echo "1.1.1.1 via 10.0.0.1 dev eth0 src 203.0.113.7 uid 0"');

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('result=[203.0.113.7]');
});

it('explains a missing network instead of a bare IP failure', function () {
    expect(installerFn('resolve_hostnames'))
        ->toContain('ip=$(local_route_ip)')
        ->toContain('needs a working internet connection');
});
