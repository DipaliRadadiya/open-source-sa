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
    return releaseScript()->render(new PanelUpdate([
        'id' => 1,
        'from_commit' => str_repeat('a', 40),
    ]), '1.0.2', $dryRun);
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
    //
    // The region ends at sync_privileges, not at maintenance_on. That step
    // rewrites /etc/sudoers.d, which is outside the release directory and
    // therefore outside the property this test is about — slicing to
    // maintenance_on would have kept passing while quietly covering a step
    // that does change the machine.
    $start = strpos($script, 'note preflight');
    $end = strpos($script, 'note sync_privileges');
    $safeRegion = substr($script, $start, $end - $start);

    expect($safeRegion)->toContain('note '.ReleaseUpdateScript::LAST_SAFE_STEP);

    // ...and it is the *last* thing in it, so a step added on the wrong side
    // of the boundary fails here rather than silently giving up the property.
    $steps = ReleaseUpdateScript::STEPS;
    $boundary = array_search(ReleaseUpdateScript::LAST_SAFE_STEP, $steps, true);

    expect($steps[$boundary + 1])->toBe('sync_privileges');

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

it('refuses a target already contained in the live commit before building it', function () {
    $script = renderedScript();

    expect($script)->toContain('merge-base --is-ancestor')
        ->and($script)->toContain('finish failed target_not_newer')
        ->and(strpos($script, 'merge-base --is-ancestor'))
        ->toBeLessThan(strpos($script, 'note link_shared'));
});

it('restarts php-fpm after both the release swap and a rollback', function () {
    $script = renderedScript();
    $restart = 'systemctl restart '.config('panel_update.services.php_fpm');

    // Reload can preserve workers and shared OPcache entries for the stable
    // current/backend path, making the new release answer as the old version.
    expect(substr_count($script, $restart))->toBe(2)
        ->and($script)->not->toContain('systemctl reload '.config('panel_update.services.php_fpm'));
});

it('verifies the frontend, not only the backend', function () {
    // The old health check curled the backend alone, which is why a client
    // could be told the update succeeded and reload into a service that was
    // still booting.
    $script = renderedScript();

    expect($script)->toContain('/api/health')
        ->and($script)->toContain('grep -qF')
        ->and($script)->toContain('$EXPECTED_VERSION')
        ->and($script)->not->toContain('grep -q "')
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

it('syncs privileges before the restart, and never fails the update over it', function () {
    $script = renderedScript();

    // Against the NEW release: the whole point is to grant what the version
    // being installed needs, and only its config knows that.
    expect($script)->toMatch('#/releases/[0-9-]+/backend/artisan panel:sudoers#');

    $line = collect(explode("\n", $script))
        ->first(fn (string $l): bool => str_contains($l, 'panel:sudoers'));

    // `|| echo` rather than a bare call. The script runs under `set -e` with an
    // ERR trap, so an unguarded failure would roll the whole update back — and
    // an otherwise-good update refused over a privilege grant makes the update
    // itself the outage, on a server whose existing grant still works.
    expect($line)->toContain('|| echo');

    // Before the services come up on the new code, or the new code's first
    // privileged operation is the one that discovers the grant is stale.
    expect(strpos($script, 'note sync_privileges'))
        ->toBeLessThan(strpos($script, 'note restart_services'));
});
