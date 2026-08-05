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

    it('pins PATH for the frontend build', function () {
        // npm's shebang is `env node`; unpinned, the build silently uses
        // whatever node happens to be first on PATH.
        expect($this->script)->toContain('env "PATH='.config('panel_update.node_bin_dir'));
    });

    it('echoes instead of executing in dry-run mode', function () {
        $dry = app(UpdateScript::class)->render($this->update, '99.0.0', dryRun: true);

        expect($dry)->toContain('echo DRY-RUN: git -C')
            ->and($dry)->not->toContain("\ngit -C");
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
            ->assertJsonPath('panel_update.step_number', 6)
            ->assertJsonPath('panel_update.total_steps', count(UpdateScript::STEPS));

        expect($response->json('panel_update.current_step_title'))
            ->toBe('Updating the database schema');
    });
});
