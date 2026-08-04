<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\WorkerSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Fake server state, held statically rather than on the test case.
 *
 * Pest's `test()` returns a proxy, and writes made to it from inside an HTTP
 * request do not reliably reach the test — which silently turns every
 * assertion about what systemd was asked to do into an assertion about an
 * empty array. Statics have no such ambiguity.
 */
class WorkerFake
{
    /** @var array<int, string> Units systemd currently considers active. */
    public static array $active = [];

    /** @var array<int, string> Every command the panel ran, in order. */
    public static array $ran = [];

    public static string $env = '';

    /** @var array<int, string> Paths that exist on the fake filesystem. */
    public static array $present = [];

    public static function reset(): void
    {
        self::$active = [];
        self::$ran = [];
        self::$env = "APP_ENV=production\nCACHE_STORE=redis\n";
        self::$present = ['/home/workerowner/queued.test/artisan'];
    }
}

/*
 * Workers are systemd template units — one file, N instances — so the tests
 * that matter are about the multiple: that asking for four starts four, that
 * lowering the count actually stops the surplus, and that "three of four" is
 * reported as its own state rather than rounded to a green dot.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'workerowner', 'home_path' => '/home/workerowner']);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Queued Site',
        'domain' => 'queued.test',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    WorkerFake::reset();
});

function fakeWorkerSystemd(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;

        WorkerFake::$ran[] = implode(' ', $args);

        if ($binary === 'test') {
            $path = $args[2] ?? '';
            $exists = in_array($path, WorkerFake::$present, true)
                || $path === '/home/workerowner/queued.test/.env';

            return Process::result(exitCode: $exists ? 0 : 1);
        }

        if ($binary === 'cat') {
            return Process::result(output: WorkerFake::$env);
        }

        if ($binary === 'systemctl') {
            $verb = $args[1] ?? '';
            // `daemon-reload` has no unit argument at all.
            $unit = ($args[2] ?? '') === '--quiet' ? ($args[3] ?? '') : ($args[2] ?? '');

            if ($verb === 'is-active') {
                return Process::result(exitCode: in_array($unit, WorkerFake::$active, true) ? 0 : 1);
            }

            // Starting marks it active; stopping clears it — enough for the
            // supervisor to be exercised rather than mocked away.
            if (in_array($verb, ['start', 'restart'], true)) {
                WorkerFake::$active = array_values(array_unique([...WorkerFake::$active, $unit]));
            }

            if ($verb === 'stop') {
                WorkerFake::$active = array_values(array_diff(WorkerFake::$active, [$unit]));
            }
        }

        return Process::result(exitCode: 0);
    });
}

function workerUrl(string $suffix = ''): string
{
    return '/api/applications/'.test()->application->id.'/workers'.$suffix;
}

function workerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Queue worker',
        'command' => 'php8.4 artisan queue:work --sleep=3 --tries=3',
        'kind' => 'queue',
        'processes' => 1,
    ], $overrides);
}

it('offers presets for the framework it finds, not a blank box', function () {
    fakeWorkerSystemd();

    $response = $this->actingAs($this->admin)->getJson(workerUrl())->assertOk();
    $presets = collect($response->json('presets'));

    // The empty state is the feature: nobody should have to remember
    // `--sleep=3 --tries=3` to get a queue running.
    expect($presets->pluck('key')->all())->toBe(['queue', 'horizon', 'custom'])
        ->and($presets->firstWhere('key', 'queue')['command'])->toContain('artisan queue:work')
        ->and($presets->firstWhere('key', 'queue')['title'])->toBe('Queue worker');
});

it('creates a worker and starts as many copies as asked for', function () {
    fakeWorkerSystemd();

    $response = $this->actingAs($this->admin)
        ->postJson(workerUrl(), workerPayload(['processes' => 3]))
        ->assertCreated();

    expect($response->json('worker.running'))->toBe(3)
        ->and($response->json('worker.state'))->toBe('running');

    $worker = Worker::first();
    foreach ([1, 2, 3] as $number) {
        expect(WorkerFake::$active)->toContain("sv-worker-{$worker->id}@{$number}.service");
    }
});

it('reports a partly-running pool as its own state', function () {
    fakeWorkerSystemd();
    $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload(['processes' => 3]));

    $worker = Worker::first();

    // One instance died. A green dot would hide this, and a half-dead worker
    // pool is exactly the state nobody notices until the queue backs up.
    WorkerFake::$active = array_values(array_diff(WorkerFake::$active, ["sv-worker-{$worker->id}@2.service"]));

    $status = app(WorkerSupervisor::class)->status($worker->load('application.systemUser'));

    expect($status)->toMatchArray(['running' => 2, 'requested' => 3, 'state' => 'degraded']);
});

it('stops the surplus when the process count is lowered', function () {
    fakeWorkerSystemd();
    $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload(['processes' => 4]));
    $worker = Worker::first();

    $this->actingAs($this->admin)
        ->putJson(workerUrl('/'.$worker->id), workerPayload(['processes' => 2]))
        ->assertOk();

    // Instances 3 and 4 must actually stop. Nothing else in the system would
    // ever notice they were still consuming the queue.
    expect(WorkerFake::$active)->toContain("sv-worker-{$worker->id}@1.service")
        ->and(WorkerFake::$active)->not->toContain("sv-worker-{$worker->id}@3.service")
        ->and(WorkerFake::$active)->not->toContain("sv-worker-{$worker->id}@4.service");
});

it('refuses a worker whose unit will not stay up', function () {
    // Every command reports success, but the unit is never active — exactly
    // what a mistyped command does, and the reason `start` alone cannot be
    // trusted to mean the worker is running.
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return Process::result(exitCode: ($args[1] ?? '') === 'is-active' ? 1 : 0);
    });

    $this->actingAs($this->admin)
        ->postJson(workerUrl(), workerPayload())
        ->assertStatus(500);
});

describe('restarting', function () {
    it('asks a queue worker to finish its job rather than killing it', function () {
        fakeWorkerSystemd();
        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload());
        $worker = Worker::first();

        WorkerFake::$ran = [];
        $this->actingAs($this->admin)->postJson(workerUrl("/{$worker->id}/restart"))->assertOk();

        // `queue:restart` lets the worker finish the job it is holding and
        // exit; a unit restart could kill it mid-payment.
        expect(collect(WorkerFake::$ran)->contains(fn (string $c) => str_contains($c, 'artisan queue:restart')))
            ->toBeTrue();
    });

    it('uses horizon:terminate for Horizon', function () {
        fakeWorkerSystemd();
        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload([
            'name' => 'Horizon', 'command' => 'php8.4 artisan horizon', 'kind' => 'horizon',
        ]));
        $worker = Worker::first();

        WorkerFake::$ran = [];
        $this->actingAs($this->admin)->postJson(workerUrl("/{$worker->id}/restart"));

        expect(collect(WorkerFake::$ran)->contains(fn (string $c) => str_contains($c, 'artisan horizon:terminate')))
            ->toBeTrue();
    });

    it('restarts the unit for a command with no such protocol', function () {
        fakeWorkerSystemd();
        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload([
            'name' => 'Custom', 'command' => '/usr/bin/myscript', 'kind' => 'custom',
        ]));
        $worker = Worker::first();

        WorkerFake::$ran = [];
        $this->actingAs($this->admin)->postJson(workerUrl("/{$worker->id}/restart"));

        expect(collect(WorkerFake::$ran)->contains(fn (string $c) => str_contains($c, "restart sv-worker-{$worker->id}@1")))
            ->toBeTrue();
    });
});

describe('guardrails', function () {
    it('refuses Horizon alongside a queue worker', function () {
        fakeWorkerSystemd();
        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload());

        // Horizon supervises its own workers, so both together means every job
        // is handled twice — and neither tool can see the other.
        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload([
                'name' => 'Horizon', 'command' => 'php8.4 artisan horizon', 'kind' => 'horizon',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('kind');
    });

    it('refuses a command with shell syntax in it', function () {
        fakeWorkerSystemd();

        // systemd execs ExecStart directly — a pipe would be passed to the
        // binary as a literal argument rather than doing what it looks like.
        foreach (['php artisan queue:work | tee log', 'sh -c "x"; rm -rf /', 'php `whoami`'] as $command) {
            $this->actingAs($this->admin)
                ->postJson(workerUrl(), workerPayload(['command' => $command]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('command');
        }
    });

    it('caps how many copies can be asked for', function () {
        fakeWorkerSystemd();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload(['processes' => 999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('processes');
    });

    it('warns when queue:restart would silently do nothing', function () {
        // The find that justifies the check list: `queue:restart` leaves a flag
        // in the cache for workers to read, and the array driver does not
        // survive the process that wrote it. The command succeeds, nothing
        // restarts, and deploys quietly run old code in the queue forever.
        WorkerFake::$env = "APP_ENV=production\nCACHE_STORE=array\n";
        fakeWorkerSystemd();

        $checks = collect($this->actingAs($this->admin)->getJson(workerUrl())->json('checks'));

        expect($checks->pluck('code'))->toContain('cache_driver_array')
            ->and($checks->firstWhere('code', 'cache_driver_array')['title'])
            ->toBe('Workers cannot be restarted automatically');
    });

    it('says nothing about the cache when the driver is fine', function () {
        fakeWorkerSystemd();

        expect($this->actingAs($this->admin)->getJson(workerUrl())->json('checks'))->toBe([]);
    });
});

describe('which sites have workers', function () {
    it('includes blank PHP and Craft, which run their own code', function () {
        foreach (['php', 'craftcms', 'statamic', 'git'] as $type) {
            // Not `toContain($needle, $message)` — the second argument there is
            // another value to look for, not a failure message.
            $features = app(SiteTypeManager::class)->find($type)->features();

            expect(in_array('app_worker', $features, true))->toBeTrue("{$type} should have workers");
        }
    });

    it('excludes marketplace apps that manage their own background work', function () {
        foreach (['wordpress', 'joomla', 'static', 'phpmyadmin'] as $type) {
            expect(app(SiteTypeManager::class)->find($type)->features())
                ->not->toContain('app_worker');
        }
    });

    it('is refused at the endpoint for a site that has none', function () {
        fakeWorkerSystemd();

        $wordpress = Application::create([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Blog', 'domain' => 'blog.test', 'site_type' => 'wordpress',
            'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/applications/{$wordpress->id}/workers")
            ->assertNotFound();
    });
});

describe('permissions', function () {
    it('lets a viewer read but not change', function () {
        fakeWorkerSystemd();
        $user = User::factory()->create();
        grantPermission($user, 'app_worker', view: true, manage: false);

        $this->actingAs($user)->getJson(workerUrl())->assertOk();
        $this->actingAs($user)->postJson(workerUrl(), workerPayload())->assertForbidden();
    });

    it('denies a user with no grant', function () {
        fakeWorkerSystemd();

        $this->actingAs(User::factory()->create())->getJson(workerUrl())->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        fakeWorkerSystemd();

        // Its own test: an earlier actingAs in the same test leaves the guard
        // resolved, and the request would answer for that user instead.
        $this->getJson(workerUrl())->assertUnauthorized();
    });
});
