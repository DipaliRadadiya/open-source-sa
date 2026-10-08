<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Process;

/*
 * FS-B3: a download that never happened — wordpress.org unreachable — was
 * saved with no reason, so the user saw only "download" and could not tell a
 * broken installer from a network that will be back in a minute.
 */

it('names a host that could not be reached', function (string $said) {
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-dl',
        result: Process::result(output: '', errorOutput: $said, exitCode: 6),
    );

    expect(ProvisioningFailedException::fromResult('download', $result)->reason)->toBe('download_unreachable')
        ->and(__('application.failure_reason.download_unreachable'))->not->toBe('application.failure_reason.download_unreachable');
})->with([
    'curl, no DNS' => ['curl: (6) Could not resolve host: wordpress.org'],
    'curl, no route' => ['curl: (7) Failed to connect to wordpress.org port 443 after 3 ms: Connection refused'],
    'wget' => ['wget: unable to resolve host address ‘downloads.joomla.org’'],
]);

it('leaves a failure that is not the network unclassified', function () {
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-dl2',
        result: Process::result(output: '', errorOutput: 'tar: Unexpected EOF in archive', exitCode: 2),
    );

    expect(ProvisioningFailedException::fromResult('download', $result)->reason)->toBeNull();
});
