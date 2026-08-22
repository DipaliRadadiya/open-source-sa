<?php

use App\Enums\PanelUpdateStatus;
use App\Models\ActivityLog;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\Panel\PanelUpdateRunner;
use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // The state dir must not be the real /var/lib path in tests.
    config()->set('panel_update.state_dir', storage_path('framework/testing/panel-update'));

    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v99.0.0'])]);
});

function applyHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/*
 * The apply step cannot be exercised end to end here — it checks out a git tag
 * from a remote and restarts system services. What CAN be pinned down is the
 * script it generates, and these tests do exactly that: the generated bash is
 * the artefact that runs on a user's server, so it is the thing worth
 * asserting line by line.
 */
describe('the generated update script', function () {
    beforeEach(function () {
        $this->update = PanelUpdate::create([
            'user_id' => $this->admin->id,
            'status' => PanelUpdateStatus::Pending,
            'from_version' => '1.0.0',
            'from_commit' => 'abc1234def5678901234567890abcdef12345678',
            'to_version' => '99.0.0',
        ]);

        $this->script = app(UpdateScript::class)->render($this->update, '99.0.0');
    });

    it('runs every step in the documented order', function () {
        $positions = collect(UpdateScript::STEPS)
            ->map(fn (string $step): int => strpos($this->script, "note {$step}"));

        expect($positions->every(fn ($p) => $p !== false))->toBeTrue()
            ->and($positions->all())->toBe($positions->sort()->values()->all());
    });

    it('backs up the database before it migrates', function () {
        expect(strpos($this->script, 'panel:backup-database'))
            ->toBeLessThan(strpos($this->script, 'artisan migrate --force'));
    });

    it('takes the panel down before backing up, so no write is lost on rollback', function () {
        expect(strpos($this->script, 'artisan down'))
            ->toBeLessThan(strpos($this->script, 'panel:backup-database'));
    });

    it('seeds permissions after migrating', function () {
        expect(strpos($this->script, 'artisan migrate --force'))
            ->toBeLessThan(strpos($this->script, 'db:seed --class=PermissionSeeder'));
    });

    it('health-checks through the configured URL, not 127.0.0.1', function () {
        // The web server routes by hostname; a bare-IP request 404s, which
        // would fail every check and roll back successful updates.
        expect($this->script)->toContain(rtrim(config('app.url'), '/').'/api/health')
            ->and($this->script)->not->toContain('127.0.0.1');
    });

    it('asserts the new version answered, not merely that something answered', function () {
        expect($this->script)->toContain('"version":"99.0.0"');
    });

    it('restores the previous commit and leaves maintenance mode on any failure', function () {
        expect($this->script)->toContain('trap rollback ERR')
            ->and($this->script)->toContain('checkout --force abc1234def5678901234567890abcdef12345678')
            // A panel stuck in maintenance is worse than a failed update.
            ->and(substr_count($this->script, 'artisan up'))->toBeGreaterThan(1);
    });

    it('defaults to writing progress outside the repository', function () {
        // A state file inside the tree is destroyed by the checkout whose
        // progress it is recording. beforeEach() redirects this to a temp
        // path for the other tests, so assert the shipped default itself —
        // that is the value a real installation runs with.
        $default = require config_path('panel_update.php');

        expect($default['state_dir'])->not->toStartWith(dirname(base_path()));
    });

    it('builds as the panel user, because a transient unit has no HOME', function () {
        // The bug this pins: `systemd-run` starts the update with a minimal
        // environment and HOME unset. Composer refuses to run at all -- "The
        // HOME or COMPOSER_HOME environment variable must be set" -- so every
        // update on every server failed at composer_install and rolled back.
        // Confirmed on a real box: a transient unit prints an empty $HOME.
        //
        // Running as root was wrong for a second reason that would have
        // surfaced the moment the first was fixed: vendor/, node_modules/ and
        // .next/ would be owned by root under services that run as `panel`,
        // and Next.js cannot write its cache into a directory it does not own.
        $user = config('panel_update.app_user');

        expect($this->script)->toContain("sudo -u {$user} -H composer install")
            ->and($this->script)->toContain("sudo -u {$user} -H env \"PATH=")
            // No bare invocation left behind. Asserted on the line start so a
            // step that reverts to root fails here rather than at 3am on
            // somebody's server.
            ->and($this->script)->not->toMatch('/\n\s*composer install/')
            ->and($this->script)->not->toMatch('/\n\s*env "PATH=/');
    });

    it('never runs git as root, or the update wedges the box for good', function () {
        // `git checkout --force` writes every file it touches as the calling
        // user. Run as root against a tree install.sh chowned to `panel`, one
        // update left 1331 root-owned files on a real server -- counted on the
        // box, not estimated. The panel account could then no longer check out
        // or clean its own repository, so preflight's clean_working_tree failed
        // for good and every later update was refused before it started. A
        // single attempt wedged the machine permanently.
        $user = config('panel_update.app_user');

        foreach (['rev-parse', 'fetch', 'checkout'] as $verb) {
            expect($this->script)->toMatch('/sudo -u \''.$user.'\' -H git -c [^\n]*'.$verb.'/')
                ->and($this->script)->not->toMatch('/(?<!-H )git -c [^\n]*'.$verb.'/');
        }
    });

    it('pins PATH for the frontend build', function () {
        // npm's shebang is `env node`; unpinned, the build silently uses
        // whatever node happens to be first on PATH.
        expect($this->script)->toContain('env "PATH='.config('panel_update.node_bin_dir'));
    });

    it('echoes instead of executing in dry-run mode', function () {
        $dry = app(UpdateScript::class)->render($this->update, '99.0.0', dryRun: true);

        expect($dry)->toContain('echo DRY-RUN: sudo -u \'panel\' -H git -c')
            // The mutating ones. `checkout` and `fetch` must never run for
            // real here; the preflight read below is the deliberate exception.
            ->and($dry)->not->toContain("\ngit -c safe.directory=/var/www/panel -C '/var/www/panel' checkout")
            ->and($dry)->not->toContain("\ngit -c safe.directory=/var/www/panel -C '/var/www/panel' fetch");
    });

    it('really reads the repository during a dry run, rather than echoing that too', function () {
        // A dry run exists to answer "would the update work". Echoing every
        // command cannot answer it: on a box where git refuses the repository
        // as dubiously owned — the panel runs as its own user, the update runs
        // as root through systemd-run, which does not carry root's gitconfig —
        // a dry run reported `succeeded` while the first real command of a real
        // update would have failed, and the rollback with it.
        $dry = app(UpdateScript::class)->render($this->update, '99.0.0', dryRun: true);

        expect($dry)->toContain('note preflight_git')
            ->and($dry)->toMatch('/\nnote preflight_git\n\s*sudo -u \'panel\' -H git -c /');
    });

    it('carries the safe.directory exception on every git call', function () {
        // Not left to ambient config: install.sh writes the exception into
        // root's ~/.gitconfig, and `systemd-run` starts the update with its
        // own environment, so git never reads that file.
        $script = app(UpdateScript::class)->render($this->update, '99.0.0');

        expect($script)->not->toMatch('/(?<!-c )git -C/')
            ->and(substr_count($script, "git -c 'safe.directory="))->toBeGreaterThanOrEqual(4);
    });

    it('does not let the dry-run health check fail into a rollback', function () {
        // The health check is a pipeline. Prefixing it with `echo` would feed
        // the printed command text into grep, which never matches — so a dry
        // run would always end in a rollback and tell the operator nothing.
        $dry = app(UpdateScript::class)->render($this->update, '99.0.0', dryRun: true);

        expect($dry)->toContain("echo 'DRY-RUN: health check skipped'")
            ->and($dry)->not->toContain('| grep');
    });
});

describe('version validation', function () {
    it('accepts real release versions', function () {
        foreach (['1.0.0', 'v1.0.0', '0.1', '10.20.30.40'] as $version) {
            expect(UpdateScript::isValidVersion($version))->toBeTrue();
        }
    });

    it('rejects anything that could carry a shell payload', function () {
        // The version is interpolated into a shell script. Provenance from
        // the release host is not a reason to extend trust.
        foreach (['1.0.0; rm -rf /', '$(whoami)', '`id`', '1.0.0 && curl evil', '../../etc', ''] as $version) {
            expect(UpdateScript::isValidVersion($version))->toBeFalse();
        }
    });
});

describe('starting an update', function () {
    it('refuses when the panel is already on the newest version', function () {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.0.1'])]);

        $this->withHeaders(applyHeader())->postJson('/api/admin/panel-update')
            ->assertUnprocessable()->assertJsonValidationErrors('version');

        expect(PanelUpdate::count())->toBe(0);
    });

    it('refuses a second update while one is in flight', function () {
        PanelUpdate::create(['status' => PanelUpdateStatus::Running, 'to_version' => '99.0.0']);

        $this->withHeaders(applyHeader())->postJson('/api/admin/panel-update?dry_run=1')
            ->assertUnprocessable()->assertJsonValidationErrors('version');

        expect(PanelUpdate::count())->toBe(1);
    });

    it('denies a non-admin', function () {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/panel-update')
            ->assertForbidden();

        expect(PanelUpdate::count())->toBe(0);
    });

    it('denies an unauthenticated request', function () {
        $this->postJson('/api/admin/panel-update')->assertUnauthorized();
        expect(PanelUpdate::count())->toBe(0);
    });

});

describe('progress reconciliation', function () {
    it('reads a completed run out of the state file and logs it', function () {
        $update = PanelUpdate::create([
            'user_id' => $this->admin->id,
            'status' => PanelUpdateStatus::Running,
            'from_version' => '1.0.0',
            'to_version' => '99.0.0',
        ]);

        $path = app(UpdateScript::class)->statePath($update);
        @mkdir(dirname($path), 0750, true);
        file_put_contents($path, json_encode([
            'step' => 'health_check',
            'status' => 'succeeded',
            'commit' => 'ffffffffffffffffffffffffffffffffffffffff',
        ]));

        // This is the whole point: the process that started the update is
        // gone — php-fpm and the queue were restarted underneath it.
        $reconciled = app(PanelUpdateRunner::class)->reconcile($update);

        expect($reconciled->status)->toBe(PanelUpdateStatus::Succeeded)
            ->and($reconciled->to_commit)->toBe('ffffffffffffffffffffffffffffffffffffffff')
            ->and($reconciled->finished_at)->not->toBeNull();

        // The row itself is not where an admin looks to find out what
        // happened — the activity log is, and reconcile() is the only place
        // that ever runs once the process that started the update is gone.
        $log = ActivityLog::where('type', 'panel_update')->where('action', 'succeeded')->first();
        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->admin->id)
            ->and($log->subject_id)->toBe($update->id)
            ->and($log->properties['to_version'])->toBe('99.0.0');

        @unlink($path);
    });

    it('records the failed step as the reason, marks the rollback, and logs it', function () {
        $update = PanelUpdate::create([
            'user_id' => $this->admin->id,
            'status' => PanelUpdateStatus::Running,
            'from_version' => '1.0.0',
            'to_version' => '99.0.0',
        ]);

        $path = app(UpdateScript::class)->statePath($update);
        @mkdir(dirname($path), 0750, true);
        file_put_contents($path, json_encode([
            'step' => 'rollback',
            'status' => 'failed',
            'reason' => 'frontend_build',
        ]));

        $reconciled = app(PanelUpdateRunner::class)->reconcile($update);

        expect($reconciled->status)->toBe(PanelUpdateStatus::Failed)
            ->and($reconciled->reason)->toBe('frontend_build')
            ->and($reconciled->rolled_back)->toBeTrue();

        $log = ActivityLog::where('type', 'panel_update')->where('action', 'failed')->first();
        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->admin->id)
            ->and($log->subject_id)->toBe($update->id)
            ->and($log->properties['reason'])->toBe('frontend_build')
            ->and($log->properties['rolled_back'])->toBeTrue();

        @unlink($path);
    });

    it('does not log again once a run has already settled', function () {
        $update = PanelUpdate::create([
            'user_id' => $this->admin->id,
            'status' => PanelUpdateStatus::Succeeded,
            'to_version' => '99.0.0',
        ]);

        $path = app(UpdateScript::class)->statePath($update);
        @mkdir(dirname($path), 0750, true);
        file_put_contents($path, json_encode(['step' => 'health_check', 'status' => 'succeeded']));

        app(PanelUpdateRunner::class)->reconcile($update);

        expect(ActivityLog::where('type', 'panel_update')->where('action', 'succeeded')->count())->toBe(0);

        @unlink($path);
    });

    it('never re-opens a settled run', function () {
        $update = PanelUpdate::create([
            'status' => PanelUpdateStatus::Succeeded,
            'to_version' => '99.0.0',
        ]);

        $path = app(UpdateScript::class)->statePath($update);
        @mkdir(dirname($path), 0750, true);
        file_put_contents($path, json_encode(['step' => 'migrate', 'status' => 'running']));

        expect(app(PanelUpdateRunner::class)->reconcile($update)->status)
            ->toBe(PanelUpdateStatus::Succeeded);

        @unlink($path);
    });

    it('leaves the row alone when no state file exists yet', function () {
        $update = PanelUpdate::create([
            'status' => PanelUpdateStatus::Pending,
            'to_version' => '99.0.0',
        ]);

        expect(app(PanelUpdateRunner::class)->reconcile($update)->status)
            ->toBe(PanelUpdateStatus::Pending);
    });
});

describe('the status endpoint', function () {
    it('reports the step number and total so a progress bar can be drawn', function () {
        $update = PanelUpdate::create([
            'status' => PanelUpdateStatus::Running,
            'current_step' => 'migrate',
            'to_version' => '99.0.0',
        ]);

        $response = $this->withHeaders(applyHeader())
            ->getJson("/api/admin/panel-update/{$update->id}");

        $response->assertOk()
            ->assertJsonPath('panel_update.current_step', 'migrate')
            // Derived, not hardcoded: the ordinal is a property of the step
            // list, and pinning the number here makes inserting a step fail an
            // assertion about something else entirely.
            ->assertJsonPath('panel_update.step_number', array_search('migrate', UpdateScript::STEPS, true) + 1)
            ->assertJsonPath('panel_update.total_steps', count(UpdateScript::STEPS));

        expect($response->json('panel_update.current_step_title'))
            ->toBe('Updating the database schema');
    });
});
