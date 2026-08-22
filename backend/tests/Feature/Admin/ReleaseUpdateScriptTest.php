<?php

use App\Models\PanelUpdate;
use App\Services\Panel\PanelLayout;
use App\Services\Panel\PanelReleases;
use App\Services\Panel\ReleaseUpdateScript;

/*
 * The ordering is the design: everything that can fail happens before anything
 * that changes what is live. These assert that property directly, because a
 * step added on the wrong side of the line silently gives it up.
 */

function releaseScript(): ReleaseUpdateScript
{
    $layout = new PanelLayout('/var/www/panel/current/backend');

    return new ReleaseUpdateScript($layout, new PanelReleases($layout));
}

function renderedScript(bool $dryRun = false): string
{
    return releaseScript()->render(new PanelUpdate(['id' => 1]), '1.0.2', $dryRun);
}

it('does nothing that touches the running panel before the build is done', function () {
    // The whole point. A failure up to and including frontend_build removes a
    // directory and nothing else: the old version is still serving and no user
    // knew. The in-place update could not have this property, which is how a
    // failed update used to strand a server in maintenance mode.
    $script = renderedScript();

    // The region that *executes* before anything is live — from the first step
    // to maintenance mode. Not from the top of the file: `rollback()` is
    // defined up there and contains a swap, which only runs on failure. An
    // earlier version of this test sliced from byte zero and failed on that
    // definition, which would have been a real bug had the function been called.
    $start = strpos($script, 'note preflight');
    $end = strpos($script, 'note maintenance_on');
    $safeRegion = substr($script, $start, $end - $start);

    expect($safeRegion)->toContain('note '.ReleaseUpdateScript::LAST_SAFE_STEP);

    foreach (['artisan down', 'artisan migrate', 'mv -T', 'systemctl restart'] as $live) {
        expect($safeRegion)->not->toContain($live);
    }
});

it('migrates as late as it can while still preceding the swap', function () {
    // After the migration a rollback restores the code and not the schema, so
    // it belongs after everything that can fail cheaply — and before the swap,
    // or new code would run against an old database.
    $script = renderedScript();

    expect(strpos($script, 'note migrate'))->toBeGreaterThan(strpos($script, 'note frontend_build'))
        ->and(strpos($script, 'note migrate'))->toBeLessThan(strpos($script, 'note swap'));
});

it('records that a rollback could not undo the migration', function () {
    // The code is back and the schema is not. Saying only "failed" would imply
    // the rollback was complete, and the operator would not go looking for the
    // backup taken at step two.
    expect(renderedScript())->toContain('MIGRATED=1')
        ->and(renderedScript())->toContain(':migrated');
});

it('captures the live release before anything moves', function () {
    // Rollback needs to know where to return to, and after the swap the symlink
    // no longer knows what it used to point at.
    $script = renderedScript();

    expect(strpos($script, 'PREVIOUS=$(readlink'))->toBeLessThan(strpos($script, 'note swap'));
});

it('refuses when the shared env has no APP_KEY', function () {
    // A release with no .env generates its own key, and every encrypted column
    // — storage secrets, git tokens, database passwords — becomes unreadable.
    expect(renderedScript())->toContain('APP_KEY');
});

it('builds as the panel user, never as root', function () {
    // install.sh builds this way. A root build leaves node_modules and .next
    // owned by root under a service that is not, and Next.js then cannot write
    // its cache at runtime.
    $script = renderedScript();

    expect($script)->toContain('sudo -u '.config('panel_update.app_user'))
        ->and($script)->toMatch('/sudo -u \S+ -H env "PATH=[^"]+" npm --prefix \S+ ci/');
});

it('verifies the frontend, not only the backend', function () {
    // The old health check curled the backend alone, which is why a client
    // could be told the update succeeded and reload into a service that was
    // still booting.
    $script = renderedScript();

    expect($script)->toContain('/api/health')
        // And the frontend's own root, retried — `systemctl restart` returns
        // when a unit is started, not when it is ready.
        ->and($script)->toContain('seq 1 30')
        ->and($script)->toContain('is-active --quiet');
});

it('really reads the repository during a dry run', function () {
    // A dry run exists to answer "would this work". Echoing every command
    // cannot answer it — that is how a dry run reported success on a box where
    // the first real command failed.
    $dry = renderedScript(dryRun: true);

    expect($dry)->toMatch('/\nnote preflight\n[\s\S]{0,400}?\n\s*git -c safe\.directory=/')
        ->and($dry)->toContain('echo DRY-RUN: ');
});

it('never fails the update because pruning failed', function () {
    // Disk left uncollected is untidy. An update reported as failed over it is
    // a lie about work that succeeded.
    expect(renderedScript())->toMatch('/note prune\n[\s\S]*?\|\| true/');
});
