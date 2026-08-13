<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Services\Runtime\InstallProgress;
use App\Services\Runtime\InstallTracker;

/*
 * The step has to come out of apt's own output, not a timer.
 *
 * A bar that advances on elapsed time keeps moving while apt sits blocked on a
 * dpkg lock — which is exactly the moment someone is watching it — and tells
 * the operator a story nothing is checking. Every assertion here is about the
 * step only moving because the server said something.
 */

beforeEach(function () {
    $this->install = RuntimeInstall::create([
        'runtime' => 'php',
        'version' => '8.4',
        'extension' => '',
        'status' => InstallStatus::Installing,
        'started_at' => now(),
    ]);
});

it('starts at preparing, before apt has said anything', function () {
    expect((new InstallProgress($this->install))->step())->toBe('preparing');
});

it('moves through the phases apt announces', function () {
    $progress = new InstallProgress($this->install);

    expect($progress->push("Get:1 http://ppa/ php8.4-fpm 8.4.1\n"))->toBeTrue()
        ->and($progress->step())->toBe('downloading');

    expect($progress->push("Unpacking php8.4-fpm (8.4.1) ...\n"))->toBeTrue()
        ->and($progress->step())->toBe('unpacking');

    expect($progress->push("Setting up php8.4-fpm (8.4.1) ...\n"))->toBeTrue()
        ->and($progress->step())->toBe('configuring');
});

it('does not move for output it does not recognise', function () {
    $progress = new InstallProgress($this->install);

    // The honest answer to "what is it doing" is the last thing it said, not
    // a step invented to fill the silence.
    expect($progress->push("Reading package lists...\nBuilding dependency tree...\n"))->toBeFalse()
        ->and($progress->step())->toBe('preparing');
});

it('never goes backwards, however apt interleaves its work', function () {
    $progress = new InstallProgress($this->install);
    $progress->push("Setting up php8.4-cli ...\n");

    // apt unpacks the next package after configuring the last one. A display
    // flicking between "configuring" and "unpacking" looks broken.
    expect($progress->push("Unpacking php8.4-fpm (8.4.1) ...\n"))->toBeFalse()
        ->and($progress->step())->toBe('configuring');
});

it('takes the furthest step when one chunk carries several', function () {
    $progress = new InstallProgress($this->install);

    $progress->push("Get:1 http://ppa/ php8.4-fpm\nUnpacking php8.4-fpm ...\nSetting up php8.4-fpm ...\n");

    expect($progress->step())->toBe('configuring');
});

it('reads fnm\'s phases too, not only apt\'s', function () {
    // Node installs through fnm, which announces the same three phases in
    // different words. Without these the step would sit at "preparing" for
    // the whole install — honest, but useless.
    $progress = new InstallProgress($this->install);

    expect($progress->push("Downloading https://nodejs.org/dist/v20.11.0/node-v20.11.0-linux-x64.tar.xz\n"))->toBeTrue()
        ->and($progress->step())->toBe('downloading');

    $progress->push("Extracting...\n");
    expect($progress->step())->toBe('unpacking');

    $progress->push("Installing Node v20.11.0\n");
    expect($progress->step())->toBe('configuring');
});

it('is not moved by a package that merely has a phase in its name', function () {
    $progress = new InstallProgress($this->install);

    // apt lists package names in prose; "Setting up" and "Unpacking" are
    // anchored to the start of a line so a name cannot impersonate them.
    expect($progress->push("The following NEW packages will be installed:\n  unpacking-tools\n"))->toBeFalse()
        ->and($progress->step())->toBe('preparing');
});

it('keeps the end of the output, which is the part that explains a failure', function () {
    $progress = new InstallProgress($this->install);

    $progress->push(str_repeat("Get:1 a long line of apt noise that nobody reads\n", 400));
    $progress->push("E: Unable to locate package php8.9-fpm\n");

    expect(strlen($progress->output()))->toBeLessThanOrEqual(InstallProgress::MAX_OUTPUT_BYTES)
        ->and($progress->output())->toContain('E: Unable to locate package php8.9-fpm')
        // Trimmed on a line boundary, so the first line shown is not half a
        // sentence.
        ->and($progress->output())->not->toStartWith('long line');
});

it('writes the step and the output to the row', function () {
    $progress = new InstallProgress($this->install);
    $progress->push("Setting up php8.4-fpm ...\n");
    $progress->persist();

    expect($this->install->fresh()->current_step)->toBe('configuring')
        ->and($this->install->fresh()->output)->toContain('Setting up php8.4-fpm');
});

it('clears the last attempt\'s progress when an install is retried', function () {
    $progress = new InstallProgress($this->install);
    $progress->push("E: Could not get lock\n");
    $progress->persist();

    app(InstallTracker::class)->start('php', '8.4');

    // Showing the previous attempt's failure under a fresh spinner would have
    // the operator reading an error that has already been retried.
    expect($this->install->fresh()->output)->toBeNull()
        ->and($this->install->fresh()->current_step)->toBeNull();
});
