<?php

use App\Services\Runtime\InstallTracker;
use App\Services\Runtime\RuntimeProgress;

/*
 * FS-C18 (fresh-server QA, re-checked 9 Oct): Remove PHP 8.3 with its FPM
 * package held → apt refuses → the job records remove_failed — and GET /php
 * showed 8.3 as `ready` with no reason. The version is still on disk with
 * nothing missing, which is what a failed removal looks like, and the screen
 * took that to mean the failure was behind it.
 */
function progressFor(string $version): array
{
    $onDisk = [['version' => $version, 'missing_packages' => []]];

    return collect(app(RuntimeProgress::class)->apply('php', $onDisk, [])['versions'])->firstWhere('version', $version);
}

it('keeps a failed removal on screen, with its reason', function (string $reason) {
    $installs = app(InstallTracker::class);
    $installs->startRemoval('php', '8.3');
    $installs->fail('php', '8.3', null, $reason, 'ref-fsc18');

    $row = progressFor('8.3');

    expect($row['status'])->toBe('failed')
        ->and($row['reason'])->toBe($reason)
        ->and($row['reference'])->toBe('ref-fsc18');
})->with(['remove_failed', 'remove_timed_out', 'remove_worker']);

it('still treats a failed install that has since completed as settled', function () {
    $installs = app(InstallTracker::class);
    $installs->start('php', '8.3');
    $installs->fail('php', '8.3', null, 'install_failed');

    expect(progressFor('8.3')['status'])->toBe('ready');
});
