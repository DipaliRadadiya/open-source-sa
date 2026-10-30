<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Process;

/*
 * A container that could not bind its published host port.
 *
 * Found in Docker QA on a real box (.115, 2026-10-30): a pasted file publishing
 * 80 was accepted and then failed at "Container start" with `failed_reason` null,
 * so the page showed only a support reference. The form now refuses a port that
 * is listened on; this names the failure for the race and for anything that
 * reaches the server another way.
 *
 * **Both strings were captured on that box** (Docker 29.9.2), not written from
 * memory.
 */

function portFailure(string $step, string $errorOutput): ?string
{
    Process::fake();

    return ProvisioningFailedException::fromResult($step, new ServerOpsResult(
        ok: false,
        reference: 'ref-port',
        result: Process::result(output: '', errorOutput: $errorOutput, exitCode: 1),
    ))->reason;
}

it('names a port the web server already holds', function () {
    expect(portFailure('container_start', 'Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint sv-app-111-app-1 (8fc2f9adea984d9af57d416b5bafb344384724b1b227f7f5b9627ab8577ed164): failed to bind host port 127.0.0.1:80/tcp: address already in use'))
        ->toBe('container_port_in_use');
});

it('names a port another container already holds', function () {
    expect(portFailure('container_start', 'docker: Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint svqa-port-b (505355f115f472f0526a73e8fff8552f616481119154812ee3802899d1bd746b): Bind for 127.0.0.1:29999 failed: port is already allocated'))
        ->toBe('container_port_in_use');
});

it('does not claim it for another step', function () {
    // "address already in use" from a deploy script is somebody else's process.
    expect(portFailure('script', 'Error: listen EADDRINUSE: bind host port 0.0.0.0:3000: address already in use'))
        ->not->toBe('container_port_in_use');
});

it('leaves an unrelated container start failure unclassified', function () {
    // A wrong reason is worse than none.
    expect(portFailure('container_start', 'Error response from daemon: OCI runtime create failed: exec: "/entrypoint.sh": permission denied'))
        ->toBeNull();
});
