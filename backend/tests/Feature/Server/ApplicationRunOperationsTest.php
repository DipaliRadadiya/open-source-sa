<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\ProvisionApplication;
use App\Jobs\RedeployContainerApplication;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationFeed;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\StrandedProvisioning;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * D3 (Docker tester list, 2026-11-03): Background jobs stayed empty while a
 * 1-click install or a Pull and redeploy ran — `GET /operations` answered
 * `[]` in every poll. Installs (any site type) and container redeploys are
 * now kinds of their own, read like every other kind.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->site = Application::forceCreate([
        'system_user_id' => SystemUser::create(['username' => 'ghost', 'home_path' => '/home/ghost'])->id,
        'name' => 'Ghost', 'slug' => 'ghost', 'domain' => 'ghost.test', 'site_type' => 'docker',
        'serving_profile' => 'docker', 'status' => 'pending', 'web_root' => 'public_html',
        'image' => 'ghost:5', 'container_port' => 2368, 'app_port' => 20001,
    ]);
});

function feed(User $user, string $query = ''): array
{
    return test()->actingAs($user)->getJson('/api/operations'.$query)->assertOk()->json('operations');
}

function provisionWith(Application $site, ?Throwable $failure = null): void
{
    test()->mock(ApplicationProvisioner::class, function ($mock) use ($failure) {
        $expectation = $mock->shouldReceive('provision');
        $failure ? $expectation->andThrow($failure) : $expectation->andReturn(['directory', 'compose_up']);
    });

    (new ProvisionApplication($site->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));
}

describe('application installs', function () {
    it('shows a retried install as waiting before a worker picks it up', function () {
        Queue::fake();
        $this->site->forceFill(['status' => 'failed', 'failed_step' => 'compose_up'])->save();

        $this->actingAs($this->admin)->postJson('/api/applications/'.$this->site->id.'/provision')->assertStatus(202);

        $ops = feed($this->admin);
        expect($ops)->toHaveCount(1)
            ->and($ops[0]['kind'])->toBe('application_install')
            ->and($ops[0]['state'])->toBe('waiting')
            ->and($ops[0]['subject'])->toBe(['type' => 'application', 'id' => $this->site->id, 'name' => 'Ghost']);
    });

    it('shows an install as running while it runs, and as done afterwards', function () {
        $run = ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'install', 'status' => 'queued']);

        $seenWhileRunning = null;
        $this->mock(ApplicationProvisioner::class, function ($mock) use (&$seenWhileRunning) {
            $mock->shouldReceive('provision')->andReturnUsing(function () use (&$seenWhileRunning) {
                $seenWhileRunning = app(OperationFeed::class)
                    ->for(test()->admin, true, now()->subDay())->all();

                return ['compose_up'];
            });
        });

        (new ProvisionApplication($this->site->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));

        expect($seenWhileRunning)->toHaveCount(1)
            ->and($seenWhileRunning[0]['state'])->toBe('running')
            ->and($seenWhileRunning[0]['started_at'])->not->toBeNull();

        // The row the request opened, adopted — not a second one.
        expect(ApplicationRun::count())->toBe(1)
            ->and($run->fresh()->status)->toBe('succeeded');

        $ops = feed($this->admin);
        expect($ops[0]['kind'])->toBe('application_install')
            ->and($ops[0]['state'])->toBe('done')
            ->and($ops[0]['finished_at'])->not->toBeNull();

        // Still reported as finished to a poller asking what ended since it looked.
        $active = feed($this->admin, '?active=1&finished_after='.urlencode(now()->subMinute()->format('d-m-Y H:i:s')));
        expect(array_column($active, 'state'))->toContain('done');
    });

    it('opens a run itself for a job queued without one', function () {
        provisionWith($this->site);

        expect(ApplicationRun::sole()->kind)->toBe('install')
            ->and(ApplicationRun::sole()->status)->toBe('succeeded');
    });

    it('closes an older open row that no job will ever pick up', function () {
        $orphan = ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'install', 'status' => 'queued']);
        $current = ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'install', 'status' => 'queued']);

        provisionWith($this->site);

        expect($current->fresh()->status)->toBe('succeeded')
            ->and($orphan->fresh()->status)->toBe('failed')
            ->and(array_column(feed($this->admin, '?active=1'), 'state'))->not->toContain('waiting');
    });

    it('records a failed install with its step and reference', function () {
        provisionWith($this->site, new ProvisioningFailedException('compose_up', 'ref-123'));

        $ops = feed($this->admin);
        expect($ops[0]['state'])->toBe('failed')
            ->and($ops[0]['step'])->toBe('compose_up')
            ->and($ops[0]['reference'])->toBe('ref-123');
    });

    it('keeps a finished install done when a later deploy fails the site', function () {
        provisionWith($this->site);
        $this->site->forceFill(['status' => 'failed', 'failed_step' => 'git_clone'])->save();

        expect(feed($this->admin)[0]['state'])->toBe('done');
    });

    it('closes the run when the job dies', function () {
        $this->site->forceFill(['status' => 'provisioning'])->save();
        ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'install', 'status' => 'running', 'started_at' => now()]);

        (new ProvisionApplication($this->site->id))->failed(new RuntimeException('worker lost'));

        expect(ApplicationRun::sole()->status)->toBe('failed')
            ->and(ApplicationRun::sole()->failed_step)->toBe('worker');
    });

    it('closes the run with a stranded install', function () {
        $this->site->forceFill(['status' => 'provisioning', 'provisioning_started_at' => now()->subDay()])->save();
        ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'install', 'status' => 'running', 'started_at' => now()->subDay()]);

        expect(app(StrandedProvisioning::class)->close($this->site->fresh()))->toBeTrue()
            ->and(ApplicationRun::sole()->status)->toBe('failed')
            ->and(ApplicationRun::sole()->failed_step)->toBe('worker');
    });

    it('opens an install run when a site is created', function () {
        Queue::fake();

        // Any site type: a plain PHP site goes through the same job.
        $this->actingAs($this->admin)->postJson('/api/applications', [
            'name' => 'Blog', 'domain' => 'blog.test', 'site_type' => 'php', 'system_user_id' => $this->site->system_user_id,
        ])->assertCreated();

        Queue::assertPushed(ProvisionApplication::class);
        $run = ApplicationRun::where('kind', 'install')->sole();
        expect($run->status)->toBe('queued')
            ->and($run->user_id)->toBe($this->admin->id)
            ->and($run->application->name)->toBe('Blog');
    });
});

describe('container redeploys', function () {
    beforeEach(function () {
        $this->site->forceFill(['status' => 'active'])->save();
        Process::fake(fn ($process) => in_array('ps', $process->command, true) ? Process::result(output: "abc\n") : Process::result());
    });

    it('shows a redeploy as waiting, then running, then done', function () {
        Queue::fake();
        $this->actingAs($this->admin)->postJson('/api/applications/'.$this->site->id.'/container/pull')->assertStatus(202);

        expect(feed($this->admin))->toHaveCount(1)
            ->and(feed($this->admin)[0]['kind'])->toBe('container_redeploy')
            ->and(feed($this->admin)[0]['state'])->toBe('waiting');

        ApplicationRun::query()->update(['status' => 'running', 'started_at' => now()]);
        expect(feed($this->admin, '?active=1')[0]['state'])->toBe('running');

        ApplicationRun::query()->update(['status' => 'queued']);
        app()->call([new RedeployContainerApplication(ApplicationRun::sole()->id), 'handle']);

        $ops = feed($this->admin);
        expect($ops[0]['state'])->toBe('done')
            ->and($ops[0]['subject'])->toBe(['type' => 'application', 'id' => $this->site->id, 'name' => 'Ghost'])
            ->and($ops[0]['finished_at'])->not->toBeNull();
    });

    it('shows a failed redeploy as failed', function () {
        ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'container_redeploy', 'status' => 'failed',
            'failed_step' => 'image_pull', 'started_at' => now(), 'finished_at' => now()]);

        $ops = feed($this->admin);
        expect($ops[0]['state'])->toBe('failed')->and($ops[0]['step'])->toBe('image_pull');
    });

    it('shows a redeploy whose worker vanished as failed, not running for ever', function () {
        ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'container_redeploy', 'status' => 'running',
            'started_at' => now()->subSeconds(ApplicationRun::runningStaleAfter() + 60)]);

        expect(feed($this->admin)[0]['state'])->toBe('failed');
    });
});

describe('who sees what', function () {
    beforeEach(function () {
        ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'install', 'status' => 'running', 'started_at' => now()]);
        ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'container_redeploy', 'status' => 'running', 'started_at' => now()]);
    });

    it('shows installs to a role that can see applications, and redeploys only with app_container', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'application');

        expect(array_column(feed($viewer), 'kind'))->toBe(['application_install']);

        grantPermission($viewer, 'app_container');

        expect(array_column(feed($viewer->fresh()), 'kind'))->toEqualCanonicalizing(['application_install', 'container_redeploy']);
    });

    it('shows neither to a role with no application permissions', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'backup');

        expect(feed($viewer))->toBe([]);
    });
});

it('creates the runs table reversibly', function () {
    $migration = include database_path('migrations/2026_11_04_000001_create_application_runs_table.php');

    $migration->down();
    expect(Schema::hasTable('application_runs'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns('application_runs', ['application_id', 'kind', 'status', 'started_at', 'finished_at']))->toBeTrue();
});
