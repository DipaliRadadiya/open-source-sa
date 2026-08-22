<?php

use App\Services\Panel\PanelLayout;
use App\Services\Panel\ReleaseUpdateScript;
use App\Services\Panel\UpdateFlow;
use App\Services\Panel\UpdateScript;
use App\Services\Panel\UpdateSteps;

/*
 * Two update flows exist at once. Every server in the field is the legacy
 * in-place checkout; the release flow only applies once a migration has been
 * run deliberately. Choosing wrongly is not a cosmetic error — the release flow
 * on a legacy install builds a layout beside a working one, and the legacy flow
 * on a migrated install runs `git checkout` over a release with no `.git`.
 */

it('describes updates with the legacy steps until an install is migrated', function () {
    // No releases/ directory: this is every server today.
    $steps = new UpdateSteps(new PanelLayout('/var/www/panel/backend'));

    expect($steps->all())->toBe(UpdateScript::STEPS)
        ->and($steps->total())->toBe(count(UpdateScript::STEPS));
});

it('numbers a step against the flow that is actually running', function () {
    $legacy = new UpdateSteps(new PanelLayout('/var/www/panel/backend'));

    // `migrate` sits at a different position in each flow, which is exactly how
    // a progress bar ends up reporting something untrue.
    expect($legacy->numberOf('migrate'))
        ->toBe(array_search('migrate', UpdateScript::STEPS, true) + 1);
});

it('returns nothing for a step the running flow does not have', function () {
    // History outlives a migration: a row written by the other flow names steps
    // this list does not contain. Inventing a number would draw a progress bar
    // for a sequence that never happened.
    $legacy = new UpdateSteps(new PanelLayout('/var/www/panel/backend'));

    $onlyInRelease = collect(ReleaseUpdateScript::STEPS)
        ->reject(fn (string $step) => in_array($step, UpdateScript::STEPS, true))
        ->first();

    expect($onlyInRelease)->not->toBeNull()
        ->and($legacy->numberOf($onlyInRelease))->toBeNull()
        ->and($legacy->numberOf(null))->toBeNull();
});

it('keeps the two flows genuinely different, so choosing matters', function () {
    // If these ever converge the selection is pointless — and a test asserting
    // the choice would pass whichever way it went.
    expect(ReleaseUpdateScript::STEPS)->not->toBe(UpdateScript::STEPS)
        ->and(ReleaseUpdateScript::STEPS)->toContain('swap')
        ->and(UpdateScript::STEPS)->not->toContain('swap');
});

it('runs the legacy script on an install that has not been migrated', function () {
    // The branch that must not regress. Every server in the field is legacy, so
    // a change returning the release flow unconditionally would reach all of
    // them — building `releases/` beside a working checkout and pointing
    // services at neither.
    $flow = new UpdateFlow(
        new PanelLayout('/var/www/panel/backend'),
        app(UpdateScript::class),
        app(ReleaseUpdateScript::class),
    );

    expect($flow->script())->toBeInstanceOf(UpdateScript::class);
});

it('runs the release script once an install has releases', function () {
    // `releases/` exists here — this repository's own checkout does not have
    // one, so the layout is pointed at a directory that does, which is the
    // only difference the choice turns on.
    $root = sys_get_temp_dir().'/flow-'.bin2hex(random_bytes(4));
    mkdir($root.'/current/backend', 0755, true);
    mkdir($root.'/releases', 0755, true);

    $flow = new UpdateFlow(
        new PanelLayout($root.'/current/backend'),
        app(UpdateScript::class),
        app(ReleaseUpdateScript::class),
    );

    expect($flow->script())->toBeInstanceOf(ReleaseUpdateScript::class);

    exec('rm -rf '.escapeshellarg($root));
});
