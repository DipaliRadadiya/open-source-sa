<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Server\ServerOpsResult;
use Illuminate\Process\FakeProcessResult;

/*
 * Why a failed install failed.
 *
 * Measured on a real server, 2026-09-14: a Node install on a box whose sudo
 * grant did not cover `fnm` reported `reason: unknown` and "The install failed.
 * Quote the reference below to support." — directly above a panel field showing
 * `sudo: a password is required`. The cause was on screen and the panel said it
 * did not know the cause.
 *
 * Two bugs, one line: `$result->denied` was ignored, and the patterns were
 * matched against `output()`, which is stdout, while installers fail on stderr.
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
    // ServerOps established this from the sudo call itself. Leaving it to the
    // regex list would need one pattern per runtime for the same failure, and
    // the runtime that forgot would say "unknown".
    $reason = app(InstallFailureClassifier::class)->classify(
        'node',
        failedResult(stderr: 'sudo: a password is required', denied: true),
    );

    expect($reason)->toBe('sudo_denied');
});

it('reads stderr, where installers actually report failure', function () {
    // The bug this replaces: apt puts `E:` lines on stderr and the classifier
    // only ever saw stdout, so a missing package read as "unknown".
    $reason = app(InstallFailureClassifier::class)->classify(
        'php',
        failedResult(stderr: 'E: Unable to locate package php8.9-fpm'),
    );

    expect($reason)->toBe('package_not_found');
});

it('still reads stdout, because apt uses both', function () {
    // "No space left on device" arrives on stdout. Swapping one stream for the
    // other would have moved the bug rather than fixed it.
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
    // The end of the pipeline: a reason code is only worth having if the
    // sentence it resolves to is actionable. "Contact support" is not, for a
    // failure with a one-command fix.
    $row = new RuntimeInstall([
        'runtime' => 'node', 'version' => '22.11.0', 'extension' => '',
        'status' => InstallStatus::Failed, 'reason' => 'sudo_denied',
    ]);

    expect($row->message())->toContain('panel:sudoers')
        ->and($row->message())->not->toContain('Quote the reference');
});

it('says the same thing when a PHP extension is refused', function () {
    // Extension rows resolve to `extension_install_failed`, which does NOT fall
    // back to `install_failed` — so the key has to exist in both groups, and
    // this is what says so.
    $row = new RuntimeInstall([
        'runtime' => 'php', 'version' => '8.4', 'extension' => 'redis',
        'status' => InstallStatus::Failed, 'reason' => 'sudo_denied',
    ]);

    expect($row->message())->toContain('panel:sudoers');
});
