<?php

use App\Enums\PanelUpdateStatus;
use App\Http\Resources\PanelUpdateResource;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\Panel\ReleaseUpdateScript;
use App\Services\Panel\UpdateScript;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Lang;

/**
 * Every step the runner can write has something to say about it.
 *
 * The runner writes a step key into its state file and the screen translates
 * it. Nothing connected the two, so adding a step to either flow's `STEPS` and
 * forgetting the copy produced a screen that rendered the key itself —
 * `panel_update.reasons.create_release` — as the sentence explaining a failed
 * update. Six of the nine missing reasons were the **whole** release flow, which
 * is the default on a migrated panel, so the most likely update failure on the
 * most common layout was the one with no words at all.
 *
 * This reads the constants rather than a list somebody has to remember.
 */
function everyStep(): array
{
    return array_values(array_unique(array_merge(UpdateScript::STEPS, ReleaseUpdateScript::STEPS)));
}

function locales(): array
{
    return ['en', 'de', 'es', 'fr', 'hi', 'ja', 'pt', 'ru'];
}

it('has a progress sentence for every step of both flows, in every locale', function () {
    $missing = [];

    foreach (locales() as $locale) {
        foreach (everyStep() as $step) {
            if (! Lang::has('panel_update.steps.'.$step, $locale)) {
                $missing[] = $locale.': steps.'.$step;
            }
        }
    }

    expect($missing)->toBe([], 'A step with no line renders its own key mid-update: '.implode(', ', $missing));
});

it('has a failure sentence for every step of both flows, in every locale', function () {
    $missing = [];

    foreach (locales() as $locale) {
        foreach (everyStep() as $step) {
            if (! Lang::has('panel_update.reasons.'.$step, $locale)) {
                $missing[] = $locale.': reasons.'.$step;
            }
        }
    }

    // Including the steps that are currently non-fatal (`|| echo WARNING`).
    // Whether a step can fail the update is one `||` away from changing, and
    // copy that exists before it is needed costs nothing.
    expect($missing)->toBe([], 'A failed step with no line renders its own key: '.implode(', ', $missing));
});

it('has the rollback-could-not-undo-the-migration clause in every locale', function () {
    foreach (locales() as $locale) {
        expect(Lang::has('panel_update.reason_migrated', $locale))->toBeTrue($locale.' is missing it');
    }
});

it('says the schema was not rolled back when the reason carries that', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    $run = PanelUpdate::create([
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'swap:migrated',
        'rolled_back' => true,
    ]);

    $payload = PanelUpdateResource::make($run)->toArray(
        tap(request(), fn ($request) => $request->setUserResolver(fn () => $admin)),
    );

    // The suffix any step after `migrate` can carry. It used to reach the screen
    // as the literal string `panel_update.reasons.swap:migrated`, losing both
    // halves: what failed, and that the database was left where it was.
    expect($payload['reason_title'])
        ->toContain(__('panel_update.reasons.swap'))
        ->toContain(__('panel_update.reason_migrated'))
        ->not->toContain('panel_update.');
});

it('never renders a bare key when a reason has no line at all', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    $run = PanelUpdate::create([
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'a_step_invented_after_this_copy_was_written',
    ]);

    $payload = PanelUpdateResource::make($run)->toArray(
        tap(request(), fn ($request) => $request->setUserResolver(fn () => $admin)),
    );

    // The tests above keep this from happening. This keeps it from mattering:
    // "for an unknown reason" tells a reader more than an identifier does.
    expect($payload['reason_title'])->toBe(__('panel_update.reasons.unknown'))
        ->and($payload['reason'])->toBe('a_step_invented_after_this_copy_was_written');
});

it('says nothing rather than a key for an unknown current step', function () {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();

    $run = PanelUpdate::create([
        'status' => PanelUpdateStatus::Running,
        'current_step' => 'a_step_invented_after_this_copy_was_written',
    ]);

    $payload = PanelUpdateResource::make($run)->toArray(
        tap(request(), fn ($request) => $request->setUserResolver(fn () => $admin)),
    );

    // A progress screen showing an identifier is worse than one showing the
    // generic "working" line the frontend already falls back to.
    expect($payload['current_step_title'])->toBeNull();
});
