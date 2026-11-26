<?php

use App\Enums\InstallStatus;
use App\Jobs\InstallBuildTools;
use App\Jobs\InstallFail2ban;
use App\Jobs\InstallSupervisor;
use App\Models\RuntimeInstall;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\ServerOpsResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;

/*
 * Why a failed install failed.
 *
 * A sudo denial must classify as such (not `reason: unknown`), and the patterns must be matched
 * against stderr, because installers fail on stderr while `output()` is stdout.
 */

function failedResult(string $stdout = '', string $stderr = '', bool $denied = false): ServerOpsResult
{
    return new ServerOpsResult(
        ok: false,
        reference: 'ref-'.bin2hex(random_bytes(4)),
        result: new FakeProcessResult(exitCode: 1, output: $stdout, errorOutput: $stderr),
        denied: $denied,
    );
}

it('names a refused sudo instead of guessing from the output', function () {
    // ServerOps knows this from the sudo call itself; a regex per runtime would miss the one that
    // forgot.
    $reason = app(InstallFailureClassifier::class)->classify(
        'node',
        failedResult(stderr: 'sudo: a password is required', denied: true),
    );

    expect($reason)->toBe('sudo_denied');
});

it('reads stderr, where installers actually report failure', function () {
    // apt puts `E:` lines on stderr; a classifier reading only stdout calls a missing package "unknown".
    $reason = app(InstallFailureClassifier::class)->classify(
        'php',
        failedResult(stderr: 'E: Unable to locate package php8.9-fpm'),
    );

    expect($reason)->toBe('package_not_found');
});

it('still reads stdout, because apt uses both', function () {
    // "No space left on device" arrives on stdout: both streams must be read.
    $reason = app(InstallFailureClassifier::class)->classify(
        'php',
        failedResult(stdout: 'No space left on device'),
    );

    expect($reason)->toBe('no_space');
});

it('admits it does not know rather than guessing', function () {
    expect(app(InstallFailureClassifier::class)->classify('php', failedResult(stderr: 'something new')))
        ->toBe('unknown');
});

it('tells the user which command repairs it, in their own language', function () {
    // End of the pipeline: the sentence a reason code resolves to must be actionable, not
    // "Contact support" for a failure with a one-command fix.
    $row = new RuntimeInstall([
        'runtime' => 'node', 'version' => '22.11.0', 'extension' => '',
        'status' => InstallStatus::Failed, 'reason' => 'sudo_denied',
    ]);

    expect($row->message())->toContain('panel:sudoers')
        ->and($row->message())->not->toContain('Quote the reference');
});

it('says the same thing when a PHP extension is refused', function () {
    // Extension rows resolve to `extension_install_failed`, which does NOT fall back to
    // `install_failed`, so the key has to exist in both groups.
    $row = new RuntimeInstall([
        'runtime' => 'php', 'version' => '8.4', 'extension' => 'redis',
        'status' => InstallStatus::Failed, 'reason' => 'sudo_denied',
    ]);

    expect($row->message())->toContain('panel:sudoers');
});

/*
 * The job must hand the classifier a ServerOpsResult: a string is a TypeError and the reason
 * is never recorded.
 */
it('records why a failed install failed, instead of crashing on the way', function (string $job, string $runtime, string $reason) {
    Process::fake(fn ($process) => in_array('apt-get', (array) $process->command, true)
        ? Process::result(errorOutput: 'E: Unable to locate package nothing', exitCode: 100)
        : Process::result(exitCode: 1));
    app(InstallTracker::class)->start($runtime, 'latest');

    dispatch_sync(new $job);

    $row = app(InstallTracker::class)->current($runtime, 'latest');

    expect($row?->status?->value)->toBe('failed')
        ->and($row->reason)->toBe($reason);
})->with([
    'fail2ban' => [InstallFail2ban::class, 'fail2ban', 'package_not_found'],
    'build tools' => [InstallBuildTools::class, 'build_tools', 'unknown'],
    'supervisor' => [InstallSupervisor::class, 'supervisor', 'unknown'],
]);
