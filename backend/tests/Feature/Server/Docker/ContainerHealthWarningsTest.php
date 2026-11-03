<?php

use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * The app badge answers "does the app's main part work?", not "is every
 * container perfect?" (health badge, 2026-11-03).
 *
 * The case this exists for: Supabase on a server whose containers cannot
 * reach the internet. Its optional Edge Functions container never became
 * healthy, the other nine served Studio, Auth and Storage, and the badge said
 * Failed without naming anything. A recipe service marked `optional` now
 * reports in `health_warnings`; every other container still turns it red,
 * and the red one is named.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'supa', 'home_path' => '/home/supa']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Supabase', 'slug' => 'supabase', 'domain' => 'supa.test',
        'site_type' => 'supabase', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'kong:2.8.1', 'container_port' => 8000, 'app_port' => 3001,
        'status' => 'active', 'container_status' => 'running',
    ]);
});

/**
 * Docker answers `ps` from these containers, `[service, state, status]` with
 * an optional fourth `oneoff`, in whichever form the command asked for: the
 * old running-only two columns, or `-a` with the service labels. Null: the
 * daemon did not answer.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3?: bool}>|null  $containers
 */
function fakeDockerContainers(?array $containers, string $project): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($containers, $project, $ran) {
        $args = $process->command;
        $ran[] = $args;

        if (! in_array('ps', $args, true) || ! in_array('docker', $args, true)) {
            return Process::result(exitCode: 0);
        }

        if ($containers === null) {
            return Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1);
        }

        $all = in_array('-a', $args, true);
        $lines = [];

        foreach ($containers as $c) {
            [$service, $state, $status] = $c;

            if ($all) {
                $lines[] = implode("\t", [$project, $service, ($c[3] ?? false) ? 'True' : '', $state, $status]);
            } elseif ($state === 'running') {
                $lines[] = $project."\t".$status;
            }
        }

        return Process::result(output: implode("\n", $lines)."\n");
    });

    return $ran;
}

/** Supabase's ten services, all up and healthy, with `$changes` over them. */
function supabaseContainers(array $changes = []): array
{
    $containers = [];

    foreach (['kong', 'studio', 'auth', 'rest', 'realtime', 'storage', 'imgproxy', 'meta', 'functions', 'db'] as $service) {
        $containers[$service] = [$service, 'running', 'Up 12 minutes (healthy)'];
    }

    foreach ($changes as $service => [$state, $status]) {
        $containers[$service] = [$service, $state, $status];
    }

    return array_values($containers);
}

function healthResource(Application $application): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => User::factory()->admin()->create());

    return ApplicationResource::make($application)->toArray($request);
}

describe('a one-click app with an optional helper', function () {
    it('reads running with no warnings when every container is healthy', function (?string $stored) {
        $this->application->forceFill(['container_status' => $stored])->save();
        fakeDockerContainers(supabaseContainers(), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('running')
            ->and($shown['health_warnings'])->toBe([]);
    })->with(['running', null]);

    it('reads running and names the helper when only an optional one is unhealthy', function (?string $stored) {
        // Live on TeamCompute: functions could not fetch jsr.io and stayed
        // unhealthy, the site worked, the badge said Failed.
        $this->application->forceFill(['container_status' => $stored])->save();
        fakeDockerContainers(supabaseContainers(['functions' => ['running', 'Up 4 minutes (unhealthy)']]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('running')
            ->and($shown['health_warnings'])->toBe([['service' => 'functions', 'state' => 'unhealthy']])
            ->and($shown['last_failure'])->toBeNull();
    })->with(['running', null]);

    it('warns, not fails, for a helper that is down in any way', function (string $state, string $status, string $reported) {
        fakeDockerContainers(supabaseContainers(['functions' => [$state, $status]]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('running')
            ->and($shown['health_warnings'])->toBe([['service' => 'functions', 'state' => $reported]]);
    })->with([
        ['restarting', 'Restarting (1) 3 seconds ago', 'restarting'],
        ['exited', 'Exited (1) 2 minutes ago', 'exited'],
        ['exited', 'Exited (0) 2 minutes ago', 'exited'],
        ['created', 'Created', 'created'],
        ['paused', 'Up 5 minutes (Paused)', 'paused'],
        ['dead', 'Dead', 'exited'],
    ]);

    it('keeps a starting helper starting', function () {
        // "Not healthy yet" is not a problem, and not healthy either.
        fakeDockerContainers(supabaseContainers(['functions' => ['running', 'Up 5 seconds (health: starting)']]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('starting')
            ->and($shown['health_warnings'])->toBe([]);
    });

    it('fails and names the main part when it is unhealthy', function (?string $stored) {
        $this->application->forceFill(['container_status' => $stored])->save();
        fakeDockerContainers(supabaseContainers(['kong' => ['running', 'Up 4 minutes (unhealthy)']]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('unhealthy')
            ->and($shown['health_warnings'])->toBe([['service' => 'kong', 'state' => 'unhealthy']]);
    })->with(['running', null]);

    it('fails for any essential service, not only the role-app one', function () {
        // `auth` is role worker, and Supabase without it has no login: only
        // an explicit `optional` makes a service a helper.
        fakeDockerContainers(supabaseContainers(['auth' => ['restarting', 'Restarting (1) 2 seconds ago']]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('restarting')
            ->and($shown['health_warnings'])->toBe([['service' => 'auth', 'state' => 'restarting']]);
    });

    it('fails when the main part has stopped while its helpers keep running', function (?string $stored, string $status) {
        // The status=running read hid a stopped container, so a kong stopped
        // by hand next to nine running helpers read green over a dead site.
        // Exit 0 too: a `docker stop` that kong handles cleanly exits 0, and
        // every recipe service is `unless-stopped`, so none of them is a job
        // that is meant to finish.
        $this->application->forceFill(['container_status' => $stored])->save();
        fakeDockerContainers(supabaseContainers(['kong' => ['exited', $status]]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('exited')
            ->and($shown['health_warnings'])->toBe([['service' => 'kong', 'state' => 'exited']]);
    })->with([
        ['running', 'Exited (137) 1 minute ago'],
        ['running', 'Exited (0) 1 minute ago'],
        [null, 'Exited (0) 1 minute ago'],
    ]);

    it('reads starting, not failed, while compose has an essential service waiting to start', function () {
        // `compose up` creates kong and holds it until its dependencies'
        // healthchecks pass.
        fakeDockerContainers(supabaseContainers(['kong' => ['created', 'Created']]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('starting')
            ->and($shown['health_warnings'])->toBe([['service' => 'kong', 'state' => 'created']]);

        app()->forgetScopedInstances();
        fakeDockerContainers(supabaseContainers([
            'kong' => ['created', 'Created'],
            'auth' => ['running', 'Up 1 minute (unhealthy)'],
        ]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        // The container behind the red status comes first.
        expect($shown['container_status'])->toBe('unhealthy')
            ->and($shown['health_warnings'])->toBe([
                ['service' => 'auth', 'state' => 'unhealthy'],
                ['service' => 'kong', 'state' => 'created'],
            ]);
    });

    it('lists the main problem first, then the helper ones', function () {
        fakeDockerContainers(supabaseContainers([
            'functions' => ['running', 'Up 4 minutes (unhealthy)'],
            'kong' => ['exited', 'Exited (137) 1 minute ago'],
        ]), 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('exited')
            ->and($shown['health_warnings'])->toBe([
                ['service' => 'kong', 'state' => 'exited'],
                ['service' => 'functions', 'state' => 'unhealthy'],
            ]);
    });

    it('ignores one-off containers', function () {
        // `docker compose run` leaves its container behind, exited.
        fakeDockerContainers([...supabaseContainers(), ['db', 'exited', 'Exited (1) 1 hour ago', true]], 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('running')
            ->and($shown['health_warnings'])->toBe([]);
    });

    it('keeps a deliberate stop a stop, with nothing to warn about', function () {
        $stopped = array_map(fn ($c) => [$c[0], 'exited', 'Exited (0) 3 minutes ago'], supabaseContainers());

        $this->application->forceFill(['container_status' => null])->save();
        fakeDockerContainers($stopped, 'sv-app-'.$this->application->id);
        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBeNull()
            ->and($shown['health_warnings'])->toBe([]);

        app()->forgetScopedInstances();
        $this->application->forceFill(['container_status' => 'running'])->save();
        fakeDockerContainers($stopped, 'sv-app-'.$this->application->id);

        expect(healthResource($this->application->fresh())['container_status'])->toBe('exited');
    });

    it('keeps the stored value and warns about nothing when Docker does not answer', function () {
        fakeDockerContainers(null, 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('running')
            ->and($shown['health_warnings'])->toBe([]);
    });

    it('still asks Docker once per request', function () {
        $other = $this->application->replicate();
        $other->forceFill(['name' => 'Supabase 2', 'slug' => 'supa2', 'app_port' => 3002, 'domain' => 'supa2.test'])->save();
        $ran = fakeDockerContainers(supabaseContainers(), 'sv-app-'.$this->application->id);

        healthResource($this->application->fresh());
        healthResource($other->fresh());

        expect(collect($ran)->filter(fn ($args) => in_array('ps', $args, true))->count())->toBe(1);
    });
});

describe('an app the panel cannot split into main and helpers', function () {
    it('fails a pasted compose app for any unhealthy container, and names it', function () {
        $this->application->forceFill(['site_type' => 'docker', 'container_status' => 'running'])->save();
        fakeDockerContainers([
            ['web', 'running', 'Up 3 minutes (healthy)'],
            ['sidecar', 'running', 'Up 3 minutes (unhealthy)'],
        ], 'sv-app-'.$this->application->id);

        $shown = healthResource($this->application->fresh());

        expect($shown['container_status'])->toBe('unhealthy')
            ->and($shown['health_warnings'])->toBe([['service' => 'sidecar', 'state' => 'unhealthy']]);
    });

    it('fails a pasted compose app whose service crashed, but not for a job that finished', function () {
        $this->application->forceFill(['site_type' => 'docker', 'container_status' => 'running'])->save();
        fakeDockerContainers([
            ['web', 'running', 'Up 3 minutes'],
            ['migrate', 'exited', 'Exited (0) 3 minutes ago'],
        ], 'sv-app-'.$this->application->id);

        $done = healthResource($this->application->fresh());

        expect($done['container_status'])->toBe('running')
            ->and($done['health_warnings'])->toBe([]);

        app()->forgetScopedInstances();
        fakeDockerContainers([
            ['web', 'running', 'Up 3 minutes'],
            ['worker', 'exited', 'Exited (1) 3 minutes ago'],
        ], 'sv-app-'.$this->application->id);
        $crashed = healthResource($this->application->fresh());

        expect($crashed['container_status'])->toBe('exited')
            ->and($crashed['health_warnings'])->toBe([['service' => 'worker', 'state' => 'exited']]);
    });

    it('leaves a single-image app as it was', function (string $state, string $status, string $stored, ?string $shown) {
        $this->application->forceFill(['site_type' => 'docker', 'image' => 'nginx:1.27', 'container_status' => $stored])->save();
        fakeDockerContainers([['app', $state, $status]], 'sv-app-'.$this->application->id);

        $resource = healthResource($this->application->fresh());

        expect($resource['container_status'])->toBe($shown);
    })->with([
        ['running', 'Up 3 minutes', 'running', 'running'],
        ['running', 'Up 3 minutes (healthy)', 'running', 'running'],
        ['running', 'Up 3 minutes (unhealthy)', 'running', 'unhealthy'],
        ['exited', 'Exited (0) 1 minute ago', 'running', 'exited'],
        ['running', 'Up 3 minutes', 'not_answering', 'not_answering'],
    ]);

    it('treats every service of a recipe that is no longer shipped as essential', function () {
        $this->application->forceFill(['site_type' => 'retired-recipe', 'container_status' => 'running'])->save();
        fakeDockerContainers([
            ['web', 'running', 'Up 3 minutes (healthy)'],
            ['functions', 'running', 'Up 3 minutes (unhealthy)'],
        ], 'sv-app-'.$this->application->id);

        expect(healthResource($this->application->fresh())['container_status'])->toBe('unhealthy');
    });
});

it('keeps the resource shape: container_status as before, health_warnings always present', function () {
    // A PHP site never asks Docker, and carries an empty list.
    $php = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'custom', 'serving_profile' => 'php', 'web_root' => 'public_html',
        'status' => 'active',
    ]);
    $ran = fakeDockerContainers(supabaseContainers(), 'sv-app-'.$this->application->id);

    $shown = healthResource($php->fresh());

    expect($shown)->toHaveKeys(['container_status', 'health_warnings', 'last_failure'])
        ->and($shown['health_warnings'])->toBe([])
        ->and(collect($ran)->contains(fn ($args) => in_array('ps', $args, true)))->toBeFalse();
});
