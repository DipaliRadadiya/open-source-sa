<?php

use App\Jobs\RedeployContainerApplication;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationRun;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationRuns;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * D5 (Docker tester list, 2026-11-03): Pull and redeploy ran inside one HTTP
 * request — 19 s for a small image — and recorded nothing while it ran. A page
 * reloaded mid-pull showed a site with nothing happening to it, and Background
 * jobs (D3) stayed empty. It is now a queued job with a persisted run.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->site = Application::forceCreate([
        'system_user_id' => SystemUser::create(['username' => 'hello', 'home_path' => '/home/hello'])->id,
        'name' => 'Hello', 'slug' => 'hello', 'domain' => 'hello.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'nginxdemos/hello:0.4', 'container_port' => 80, 'app_port' => 20001,
        'status' => 'active',
    ]);
});

/**
 * A Docker box: every command succeeds, `compose ps` names a container, and
 * the verbs listed in `$fail` answer with `$error`.
 *
 * @return ArrayObject<int, list<string>> every command that ran
 */
function redeployBox(array $fail = [], string $error = 'manifest for nginxdemos/hello:nope not found: manifest unknown'): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran, $fail, $error) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }
        $ran->append($args);

        foreach ($fail as $verb) {
            if (($args[0] ?? '') === 'docker' && in_array($verb, $args, true)) {
                return Process::result(errorOutput: $error, exitCode: 18);
            }
        }

        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        // `compose config`, which the compose save validates a file with.
        if (($args[0] ?? '') === 'docker' && in_array('config', $args, true)) {
            return Process::result(output: json_encode(['services' => ['web' => [
                'image' => 'nginxdemos/hello:0.4',
                'ports' => [['host_ip' => '127.0.0.1', 'published' => '20001', 'target' => 80, 'protocol' => 'tcp']],
            ]]]));
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'info') {
            return Process::result(output: "27.3.1\n");
        }

        return Process::result();
    });

    return $ran;
}

function ranVerb(ArrayObject $ran, string $verb): bool
{
    foreach ($ran as $args) {
        if (($args[0] ?? '') === 'docker' && in_array($verb, $args, true)) {
            return true;
        }
    }

    return false;
}

function redeploy(?User $user = null)
{
    return test()->actingAs($user ?? test()->admin)->postJson('/api/applications/'.test()->site->id.'/container/pull');
}

function runningRedeploy(Application $site, array $attributes = []): ApplicationRun
{
    return ApplicationRun::create([
        'application_id' => $site->id, 'kind' => ApplicationRun::CONTAINER_REDEPLOY,
        'status' => ApplicationRun::RUNNING, 'started_at' => now(), ...$attributes,
    ]);
}

describe('the request', function () {
    it('answers 202 at once and queues the pull instead of running it', function () {
        Queue::fake();
        $ran = redeployBox();

        $response = redeploy()->assertStatus(202)
            ->assertJsonPath('redeploy.kind', 'container_redeploy')
            ->assertJsonPath('redeploy.status', 'queued')
            ->assertJsonPath('redeploy.in_flight', true)
            // The site keeps serving until the container is recreated.
            ->assertJsonPath('application.status', 'active')
            ->assertJsonPath('application.container_redeploy.in_flight', true);

        Queue::assertPushedOn('default', RedeployContainerApplication::class, fn ($job) => $job->runId === $response->json('redeploy.id'));
        expect(ranVerb($ran, 'pull'))->toBeFalse()
            // Recorded when asked for, with the person who asked as the actor.
            ->and(ActivityLog::where('type', 'application')->where('action', 'container_pulled')->where('user_id', $this->admin->id)->exists())->toBeTrue();
    });

    it('persists the redeploying state, so a reload mid-pull still shows it', function () {
        Queue::fake();
        redeployBox();
        redeploy()->assertStatus(202);

        $this->actingAs($this->admin)->getJson('/api/applications/'.$this->site->id)->assertOk()
            ->assertJsonPath('application.container_redeploy.in_flight', true)
            ->assertJsonPath('application.container_redeploy.status', 'queued');

        $this->actingAs($this->admin)->getJson('/api/applications')->assertOk()
            ->assertJsonPath('applications.0.container_redeploy.in_flight', true);
    });

    it('refuses a second redeploy while one is under way', function () {
        Queue::fake();
        redeployBox();
        redeploy()->assertStatus(202);

        redeploy()->assertStatus(409)
            ->assertJsonPath('message', __('errors/application.container_redeploy_running', ['name' => 'Hello']));

        Queue::assertPushed(RedeployContainerApplication::class, 1);
        expect(ApplicationRun::count())->toBe(1);
    });

    it('refuses while the site is still being installed, and queues nothing', function () {
        Queue::fake();
        $this->site->forceFill(['status' => 'provisioning'])->save();

        redeploy()->assertStatus(422)
            ->assertJsonPath('message', __('errors/application.container_not_running'));

        Queue::assertNothingPushed();
        expect(ApplicationRun::count())->toBe(0);
    });

    it('refuses a paused site', function () {
        Queue::fake();
        $this->site->forceFill(['disabled_at' => now()])->save();

        redeploy()->assertStatus(422);

        Queue::assertNothingPushed();
    });

    it('refuses without app_container manage', function () {
        Queue::fake();
        $viewer = User::factory()->create();
        grantPermission($viewer, 'app_container');
        grantPermission($viewer, 'application', manage: true);

        redeploy($viewer)->assertForbidden();

        Queue::assertNothingPushed();
        expect(ApplicationRun::count())->toBe(0);
    });

    it('lets a role with app_container manage redeploy', function () {
        Queue::fake();
        $operator = User::factory()->create();
        grantPermission($operator, 'app_container', manage: true);
        grantPermission($operator, 'application');

        redeploy($operator)->assertStatus(202);

        Queue::assertPushed(RedeployContainerApplication::class);
    });
});

describe('the job', function () {
    it('pulls, recreates and closes the run as succeeded', function () {
        $this->site->forceFill(['failed_step' => 'image_pull', 'failed_reason' => 'registry_auth'])->save();
        $ran = redeployBox();

        redeploy()->assertStatus(202);

        $run = ApplicationRun::sole();
        expect($run->status)->toBe('succeeded')
            ->and($run->started_at)->not->toBeNull()
            ->and($run->finished_at)->not->toBeNull()
            ->and(ranVerb($ran, 'pull'))->toBeTrue()
            ->and(ranVerb($ran, 'up'))->toBeTrue()
            ->and($this->site->fresh()->failed_step)->toBeNull()
            ->and(ActivityLog::where('type', 'application')->where('action', 'container_pulled')->count())->toBe(1);

        $this->actingAs($this->admin)->getJson('/api/applications/'.$this->site->id)
            ->assertJsonPath('application.container_redeploy.in_flight', false)
            ->assertJsonPath('application.container_redeploy.status', 'succeeded');
    });

    it('closes the run as failed with the step when the image cannot be pulled', function () {
        redeployBox(fail: ['pull']);

        redeploy()->assertStatus(202);

        $run = ApplicationRun::sole();
        expect($run->status)->toBe('failed')
            ->and($run->failed_step)->toBe('image_pull')
            ->and($run->reference)->not->toBeEmpty()
            ->and($run->finished_at)->not->toBeNull()
            // On the application as well, which the failure card reads.
            ->and($this->site->fresh()->failed_step)->toBe('image_pull')
            // A failed pull has not taken the site down.
            ->and($this->site->fresh()->status->value)->toBe('active');
    });

    it('closes the run as failed when the site was paused before the worker got to it', function () {
        Queue::fake();
        $ran = redeployBox();
        redeploy()->assertStatus(202);
        $this->site->forceFill(['disabled_at' => now()])->save();

        app()->call([new RedeployContainerApplication(ApplicationRun::sole()->id), 'handle']);

        expect(ApplicationRun::sole()->status)->toBe('failed')
            ->and(ApplicationRun::sole()->failed_step)->toBe('container_not_running')
            ->and(ranVerb($ran, 'pull'))->toBeFalse();
    });

    it('leaves the run honest when the job itself dies', function () {
        $run = runningRedeploy($this->site);

        (new RedeployContainerApplication($run->id))->failed(new RuntimeException('timed out'));

        expect($run->fresh()->status)->toBe('failed')
            ->and($run->fresh()->failed_step)->toBe('worker')
            ->and($run->fresh()->reference)->not->toBeEmpty()
            ->and($this->site->fresh()->failed_step)->toBe('worker');
    });

    it('does nothing for a run that was already closed', function () {
        $ran = redeployBox();
        $run = runningRedeploy($this->site, ['status' => 'failed', 'failed_step' => 'worker', 'finished_at' => now()]);

        app()->call([new RedeployContainerApplication($run->id), 'handle']);

        expect(ranVerb($ran, 'pull'))->toBeFalse()
            ->and($run->fresh()->status)->toBe('failed');
    });

    it('fits inside the queue reservation window', function () {
        expect((new RedeployContainerApplication(1))->timeout)
            ->toBeLessThan((int) config('queue.connections.redis.retry_after'));
    });
});

describe('a redeploy whose worker vanished', function () {
    it('stops counting as running once past its budget, and the next redeploy closes it', function () {
        Queue::fake();
        redeployBox();
        $stale = runningRedeploy($this->site, ['started_at' => now()->subSeconds(ApplicationRun::runningStaleAfter() + 60)]);

        $this->actingAs($this->admin)->getJson('/api/applications/'.$this->site->id)
            ->assertJsonPath('application.container_redeploy.in_flight', false)
            ->assertJsonPath('application.container_redeploy.status', 'failed');

        redeploy()->assertStatus(202);

        expect($stale->fresh()->status)->toBe('failed')
            ->and($stale->fresh()->failed_step)->toBe('worker');
    });

    it('is closed by the scheduled stranded sweep', function () {
        $stale = runningRedeploy($this->site, ['started_at' => now()->subSeconds(ApplicationRun::runningStaleAfter() + 60)]);
        $fresh = runningRedeploy($this->site);

        $this->artisan('applications:close-stranded')->assertSuccessful();

        expect($stale->fresh()->status)->toBe('failed')
            ->and($fresh->fresh()->status)->toBe('running');
    });

    it('keeps a queued one waiting behind a long install', function () {
        $queued = ApplicationRun::create(['application_id' => $this->site->id, 'kind' => 'container_redeploy', 'status' => 'queued']);
        $queued->forceFill(['created_at' => now()->subSeconds(ApplicationRun::runningStaleAfter() + 60)])->save();

        expect($queued->fresh()->isInFlight())->toBeTrue()
            ->and(app(ApplicationRuns::class)->closeStranded())->toBe(0);
    });
});

describe('while a redeploy runs', function () {
    beforeEach(fn () => runningRedeploy($this->site));

    it('refuses Restart', function () {
        $ran = redeployBox();

        $this->actingAs($this->admin)->postJson('/api/applications/'.$this->site->id.'/process/restart')
            ->assertStatus(409)
            ->assertJsonPath('message', __('errors/application.container_restart_redeploying', ['name' => 'Hello']));

        expect(ranVerb($ran, 'restart'))->toBeFalse();
    });

    it('refuses a container settings save', function () {
        $ran = redeployBox();

        $this->actingAs($this->admin)->putJson('/api/applications/'.$this->site->id.'/container', ['container_port' => 8080])
            ->assertStatus(409);

        expect(ranVerb($ran, 'up'))->toBeFalse();
    });

    it('refuses a compose save', function () {
        $ran = redeployBox();

        $this->actingAs($this->admin)->putJson('/api/applications/'.$this->site->id.'/container/compose', [
            'compose' => "services:\n  web:\n    image: nginxdemos/hello:0.4\n    ports:\n      - \"127.0.0.1:20001:80\"\n",
        ])->assertStatus(409);

        expect(ranVerb($ran, 'up'))->toBeFalse();
    });

    it('refuses delete, so the job cannot bring the containers back afterwards', function () {
        redeployBox();

        $this->actingAs($this->admin)->deleteJson('/api/applications/'.$this->site->id)
            ->assertStatus(503);

        expect(Application::find($this->site->id))->not->toBeNull();
    });

    it('still restarts once the redeploy has finished', function () {
        ApplicationRun::query()->update(['status' => 'succeeded', 'finished_at' => now()]);
        $ran = redeployBox();

        $this->actingAs($this->admin)->postJson('/api/applications/'.$this->site->id.'/process/restart')
            ->assertOk();

        expect(ranVerb($ran, 'restart'))->toBeTrue();
    });
});
