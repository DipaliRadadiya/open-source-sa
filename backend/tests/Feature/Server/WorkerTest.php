<?php

use App\Jobs\InstallSupervisor;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\FrameworkDetector;
use App\Services\Server\Applications\WorkerSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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
    /**
     * How many copies of each program supervisord currently has up.
     *
     * A count rather than a set of unit names, because supervisor's `numprocs`
     * is the thing that decides it — "three of four running" is the state this
     * has to be able to represent, and a boolean could not.
     *
     * @var array<string, int>
     */
    public static array $running = [];

    /** @var array<int, string> Every command the panel ran, in order. */
    public static array $ran = [];

    public static string $env = '';

    /** @var array<int, string> Paths that exist on the fake filesystem. */
    public static array $present = [];

    /**
     * Whether supervisord is on the box.
     *
     * Default true, because it is on a fresh install. A panel upgraded from
     * before 2026-09-07 has none — `install.sh` gained the package in the same
     * commit that started writing supervisor programs, and that reaches new
     * installs only.
     */
    public static bool $supervisorInstalled = true;

    /**
     * Binaries this server's sudoers grant does not cover.
     *
     * A separate axis from `$supervisorInstalled` on purpose: the two look
     * identical through `which` and mean opposite things. One is a package to
     * install, the other a grant to rewrite, and a panel that confuses them
     * runs apt against a server that already had supervisord.
     *
     * @var array<int, string>
     */
    public static array $denied = [];

    public static function reset(): void
    {
        self::$running = [];
        self::$ran = [];
        self::$env = "APP_ENV=production\nCACHE_STORE=redis\n";
        self::$present = ['/home/workerowner/queued-site/public_html/artisan'];
        self::$supervisorInstalled = true;
        self::$denied = [];
    }

    /** The program name out of `sv-worker-shop-queue:*`. */
    public static function program(string $target): string
    {
        return explode(':', $target)[0];
    }

    /**
     * What `supervisorctl status` prints for a program.
     *
     * Real output, because the panel parses it: one line per process, with the
     * state in the second column. A test that returned a tidier shape would be
     * asserting against a format supervisord does not produce.
     */
    public static function statusOutput(string $program): string
    {
        $count = self::$running[$program] ?? 0;
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $lines[] = sprintf('%s:%s_%02d   RUNNING   pid %d, uptime 0:04:11', $program, $program, $i, 1000 + $i);
        }

        return implode("\n", $lines);
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

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Queued Site',
        'slug' => 'queued-site',
        'domain' => 'queued.test',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    WorkerFake::reset();
});

function fakeWorkerSupervisor(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;

        WorkerFake::$ran[] = implode(' ', $args);

        // Before anything else, because this is what sudo does: the binary is
        // never reached, so no fake behaviour below it may run either.
        if (in_array($binary, WorkerFake::$denied, true)) {
            return Process::result(errorOutput: "sudo: a password is required\n", exitCode: 1);
        }

        if ($binary === 'which') {
            return Process::result(exitCode: WorkerFake::$supervisorInstalled ? 0 : 1);
        }

        if ($binary === 'test') {
            $path = $args[2] ?? '';
            $exists = in_array($path, WorkerFake::$present, true)
                || $path === test()->application->rootPath().'/.env';

            return Process::result(exitCode: $exists ? 0 : 1);
        }

        if ($binary === 'cat') {
            return Process::result(output: WorkerFake::$env);
        }

        if ($binary === 'supervisorctl') {
            $verb = $args[1] ?? '';
            $program = WorkerFake::program($args[2] ?? '');

            if ($verb === 'status') {
                // Exit 3 is supervisorctl's "some processes are not running",
                // which is what it really answers for a stopped program — the
                // panel treats it as an answer rather than a failure, and a
                // fake returning 0 would never exercise that.
                $output = WorkerFake::statusOutput($program);

                return Process::result(output: $output, exitCode: $output === '' ? 3 : 0);
            }

            // Starting brings up as many copies as the row asks for, which is
            // what `numprocs` means; stopping clears them. Read from the
            // database rather than assumed, so "lower the count and re-apply"
            // is a real transition rather than one the fake pretends about.
            if (in_array($verb, ['start', 'restart'], true)) {
                $slug = str_starts_with($program, 'sv-worker-') ? substr($program, strlen('sv-worker-')) : $program;
                $processes = (int) (Worker::query()->where('slug', $slug)->value('processes') ?? 0);

                WorkerFake::$running[$program] = $processes;
            }

            if ($verb === 'stop') {
                unset(WorkerFake::$running[$program]);
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
    fakeWorkerSupervisor();

    $response = $this->actingAs($this->admin)->getJson(workerUrl())->assertOk();
    $presets = collect($response->json('presets'));

    // The empty state is the feature: nobody should have to remember
    // `--sleep=3 --tries=3` to get a queue running.
    expect($presets->pluck('key')->all())->toBe(['queue', 'horizon', 'custom'])
        ->and($presets->firstWhere('key', 'queue')['command'])->toContain('artisan queue:work')
        ->and($presets->firstWhere('key', 'queue')['title'])->toBe('Queue worker');
});

it('orders workers without case bias', function () {
    fakeWorkerSupervisor();

    foreach (['Case Zebra', 'case apple', 'CASE Banana'] as $name) {
        Worker::create([
            'application_id' => $this->application->id,
            'name' => $name,
            'command' => 'php8.4 artisan queue:work',
            'kind' => 'custom',
        ]);
    }

    $names = collect($this->actingAs($this->admin)->getJson(workerUrl())->assertOk()->json('workers'))
        ->pluck('name')->all();

    expect($names)->toBe(['case apple', 'CASE Banana', 'Case Zebra']);
});

it('finds Craft above its served web directory and runs the worker there', function () {
    $this->application->update(['site_type' => 'craftcms', 'web_root' => '/web']);
    WorkerFake::$present = ['/home/workerowner/queued-site/public_html/craft'];
    fakeWorkerSupervisor();

    $presets = collect($this->actingAs($this->admin)->getJson(workerUrl())->assertOk()->json('presets'));

    expect($presets->pluck('key')->all())->toBe(['queue', 'custom'])
        ->and($presets->firstWhere('key', 'queue')['command'])->toBe('php8.4 craft queue/listen');

    $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload([
        'command' => 'php8.4 craft queue/listen',
        'kind' => 'queue',
    ]))->assertCreated();

    // The supervisor program's `directory`, which is where Craft's own CLI
    // lives — above the served `web/`, not inside it. The worker has to run
    // from the project root or `craft` is not on the path it starts in.
    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'directory=/home/workerowner/queued-site/public_html')
        && str_contains((string) $process->input, 'command=/usr/bin/php8.4 craft queue/listen'));

    $worker = Worker::firstOrFail();
    WorkerFake::$ran = [];
    $this->actingAs($this->admin)->postJson(workerUrl("/{$worker->id}/restart"))->assertOk();

    expect(collect(WorkerFake::$ran)->contains(fn (string $command) => str_contains($command, 'artisan queue:restart')))
        ->toBeFalse()
        ->and(collect(WorkerFake::$ran)->contains(fn (string $command) => str_contains($command, "restart sv-worker-{$worker->slug}:*")))
        ->toBeTrue();
});

it('finds Statamic above its public web directory', function () {
    $this->application->update(['site_type' => 'statamic', 'web_root' => '/public']);
    WorkerFake::$present = [
        '/home/workerowner/queued-site/public_html/please',
        '/home/workerowner/queued-site/public_html/artisan',
        '/home/workerowner/queued-site/public_html/bootstrap/cache/config.php',
    ];
    fakeWorkerSupervisor();

    $presets = collect($this->actingAs($this->admin)->getJson(workerUrl())->assertOk()->json('presets'));
    $detector = app(FrameworkDetector::class);

    expect($presets->pluck('key')->all())->toBe(['queue', 'horizon', 'custom'])
        ->and($presets->firstWhere('key', 'queue')['command'])->toContain('artisan queue:work')
        ->and($detector->requiresApply($this->application, FrameworkDetector::STATAMIC))->toBeTrue()
        ->and($detector->applyCommand($this->application, FrameworkDetector::STATAMIC))
        ->toBe(['php8.4', '/home/workerowner/queued-site/public_html/artisan', 'config:clear']);
});

it('finds a brownfield Laravel project above its public web directory', function () {
    $this->application->update(['web_root' => '/public']);
    WorkerFake::$present = [
        '/home/workerowner/queued-site/public_html/artisan',
        '/home/workerowner/queued-site/public_html/bootstrap/cache/config.php',
    ];
    fakeWorkerSupervisor();

    $detector = app(FrameworkDetector::class);

    expect($detector->detect($this->application))->toBe(FrameworkDetector::LARAVEL)
        ->and($detector->root($this->application))->toBe('/home/workerowner/queued-site/public_html')
        ->and($detector->requiresApply($this->application, FrameworkDetector::LARAVEL))->toBeTrue()
        ->and($detector->applyCommand($this->application, FrameworkDetector::LARAVEL))
        ->toBe(['php8.4', '/home/workerowner/queued-site/public_html/artisan', 'config:clear']);
});

it('keeps current flat git deployments rooted in the served directory', function () {
    $this->application->update(['web_root' => '/public']);
    WorkerFake::$present = ['/home/workerowner/queued-site/public_html/public/artisan'];
    fakeWorkerSupervisor();

    $detector = app(FrameworkDetector::class);

    expect($detector->detect($this->application))->toBe(FrameworkDetector::LARAVEL)
        ->and($detector->root($this->application))->toBe('/home/workerowner/queued-site/public_html/public');
});

it('keeps n8n and Node-RED on the custom worker preset', function (string $siteType) {
    $this->application->update([
        'site_type' => $siteType,
        'serving_profile' => 'node',
        'web_root' => '/',
    ]);
    WorkerFake::$present = ['/home/workerowner/queued-site/public_html/package.json'];
    fakeWorkerSupervisor();

    expect($this->actingAs($this->admin)->getJson(workerUrl())->assertOk()->json('presets.*.key'))
        ->toBe(['custom']);
})->with(['n8n', 'nodered']);

it('creates a worker and starts as many copies as asked for', function () {
    fakeWorkerSupervisor();

    $response = $this->actingAs($this->admin)
        ->postJson(workerUrl(), workerPayload(['processes' => 3]))
        ->assertCreated();

    expect($response->json('worker.running'))->toBe(3)
        ->and($response->json('worker.state'))->toBe('running');

    // supervisor owns the copies via `numprocs`, so the evidence is how many
    // it reports running — not a unit name per copy, which no longer exists.
    $worker = Worker::first();
    expect(WorkerFake::$running["sv-worker-{$worker->slug}"] ?? 0)->toBe(3);
});

it('reports a partly-running pool as its own state', function () {
    fakeWorkerSupervisor();
    $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload(['processes' => 3]));

    $worker = Worker::first();

    // One copy died. A green dot would hide this, and a half-dead worker pool
    // is exactly the state nobody notices until the queue backs up.
    WorkerFake::$running["sv-worker-{$worker->slug}"] = 2;

    $status = app(WorkerSupervisor::class)->status($worker->load('application.systemUser'));

    expect($status)->toMatchArray(['running' => 2, 'requested' => 3, 'state' => 'degraded']);
});

it('stops the surplus when the process count is lowered', function () {
    fakeWorkerSupervisor();
    $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload(['processes' => 4]));
    $worker = Worker::first();

    $this->actingAs($this->admin)
        ->putJson(workerUrl('/'.$worker->id), workerPayload(['processes' => 2]))
        ->assertOk();

    // Two copies must actually stop. supervisor retires them on `update`
    // rather than the panel sweeping instance numbers, but the requirement is
    // unchanged: nothing else in the system would notice four processes still
    // consuming the queue.
    expect(WorkerFake::$running["sv-worker-{$worker->slug}"] ?? 0)->toBe(2);

    // And the pair that does it, in that order — `update` alone acts on a
    // config supervisord has not re-read, so the change would appear to save
    // and do nothing.
    $commands = implode(' | ', WorkerFake::$ran);
    expect($commands)->toContain('supervisorctl reread')
        ->and($commands)->toContain('supervisorctl update');
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
        fakeWorkerSupervisor();
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
        fakeWorkerSupervisor();
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
        fakeWorkerSupervisor();
        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload([
            'name' => 'Custom', 'command' => '/usr/bin/myscript', 'kind' => 'custom',
        ]));
        $worker = Worker::first();

        WorkerFake::$ran = [];
        $this->actingAs($this->admin)->postJson(workerUrl("/{$worker->id}/restart"));

        expect(collect(WorkerFake::$ran)->contains(fn (string $c) => str_contains($c, "restart sv-worker-{$worker->slug}:*")))
            ->toBeTrue();
    });
});

describe('guardrails', function () {
    it('refuses Horizon alongside a queue worker', function () {
        fakeWorkerSupervisor();
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
        fakeWorkerSupervisor();

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
        fakeWorkerSupervisor();

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
        fakeWorkerSupervisor();

        $checks = collect($this->actingAs($this->admin)->getJson(workerUrl())->json('checks'));

        expect($checks->pluck('code'))->toContain('cache_driver_array')
            ->and($checks->firstWhere('code', 'cache_driver_array')['title'])
            ->toBe('Workers cannot be restarted automatically');
    });

    it('says nothing about the cache when the driver is fine', function () {
        fakeWorkerSupervisor();

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
        fakeWorkerSupervisor();

        $wordpress = Application::forceCreate([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Blog',
            'slug' => 'blog', 'domain' => 'blog.test', 'site_type' => 'wordpress',
            'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/applications/{$wordpress->id}/workers")
            ->assertNotFound();
    });
});

describe('permissions', function () {
    it('lets a viewer read but not change', function () {
        fakeWorkerSupervisor();
        $user = User::factory()->create();
        grantPermission($user, 'app_worker', view: true, manage: false);

        $this->actingAs($user)->getJson(workerUrl())->assertOk();
        $this->actingAs($user)->postJson(workerUrl(), workerPayload())->assertForbidden();
    });

    it('denies a user with no grant', function () {
        fakeWorkerSupervisor();

        $this->actingAs(User::factory()->create())->getJson(workerUrl())->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        fakeWorkerSupervisor();

        // Its own test: an earlier actingAs in the same test leaves the guard
        // resolved, and the request would answer for that user instead.
        $this->getJson(workerUrl())->assertUnauthorized();
    });
});

describe('a worker id from another application', function () {
    it('is not editable, deletable or controllable through the wrong application', function () {
        // Route model binding resolves {worker} from the whole table. Without a
        // scope check, PUT /applications/1/workers/99 edits site 2's worker and
        // logs it against site 1 — an audit trail naming the wrong site, which
        // is worse than none because it is believed. The deployment, domain and
        // SSH-key routes all check this already; workers were the gap.
        fakeWorkerSupervisor();

        $other = Application::forceCreate([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Other Site', 'slug' => 'other-site', 'domain' => 'other.test',
            'site_type' => 'git', 'serving_profile' => 'php', 'status' => 'active',
            'web_root' => '/', 'php_version' => '8.4',
        ]);

        $foreign = Worker::create([
            'application_id' => $other->id,
            'name' => 'Someone else\'s worker',
            'command' => 'php8.4 artisan queue:work',
            'kind' => 'custom',
        ]);

        // 404, not 403: from here that worker does not exist under this
        // application, and "forbidden" would confirm it exists somewhere else.
        $this->actingAs($this->admin)
            ->putJson(workerUrl('/'.$foreign->id), workerPayload(['name' => 'Renamed']))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->postJson(workerUrl('/'.$foreign->id.'/restart'))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->deleteJson(workerUrl('/'.$foreign->id))
            ->assertNotFound();

        // Untouched, and still there.
        expect($foreign->fresh())->not->toBeNull()
            ->and($foreign->fresh()->name)->toBe('Someone else\'s worker');
    });
});

it('refuses a name or directory that would be two systemd directives', function () {
    // The name is rendered into `Description=` and the directory into
    // `WorkingDirectory=`, in a file the panel writes and systemd executes.
    // A newline there is a directive of the caller's choosing — the same
    // hazard the cron rule was written for, one file format over.
    fakeWorkerSupervisor();

    $this->actingAs($this->admin)
        ->postJson(workerUrl(), workerPayload([
            'name' => "Queue\nExecStartPre=/bin/sh -c 'id > /tmp/pwned'",
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $this->actingAs($this->admin)
        ->postJson(workerUrl(), workerPayload(['directory' => "/srv/app\nUser=root"]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('directory');

    expect(Worker::count())->toBe(0);
});

describe('the unit name', function () {
    it('is the slug, so it says what it is when read on the box', function () {
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload(['name' => 'Email Queue']))->assertCreated();
        $worker = Worker::query()->firstOrFail();

        // `sv-worker-3` tells an operator in journalctl nothing. The
        // application is in it because "queue" alone is ambiguous on a box
        // with twenty sites.
        expect($worker->slug)->toBe('queued-site-email-queue')
            ->and(app(WorkerSupervisor::class)->program($worker))
            ->toBe('sv-worker-queued-site-email-queue');
    });

    it('does not move when the worker is renamed', function () {
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload(['name' => 'Email Queue']))->assertCreated();
        $worker = Worker::query()->firstOrFail();
        $before = app(WorkerSupervisor::class)->program($worker);

        $this->actingAs($this->admin)
            ->putJson(workerUrl('/'.$worker->id), workerPayload(['name' => 'Something Else']))
            ->assertOk();

        // The whole reason identity is not the name. Renaming under a
        // name-derived unit means stop, disable, delete, write, enable, start
        // on a running process — and a half-failure leaves an orphan still
        // consuming the queue.
        expect($worker->fresh()->name)->toBe('Something Else')
            ->and($worker->fresh()->slug)->toBe('queued-site-email-queue')
            ->and(app(WorkerSupervisor::class)->program($worker->fresh()))->toBe($before);
    });

    it('suffixes rather than collides when two names reduce to one slug', function () {
        fakeWorkerSupervisor();

        // The name is unique per application, but Str::slug() is lossy: these
        // two reduce to the same string. Checking the name would let both
        // claim one unit.
        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload(['name' => 'My Queue']))->assertCreated();
        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload(['name' => 'my-queue']))->assertCreated();

        [$first, $second] = Worker::query()->orderBy('id')->get()->all();

        expect($first->slug)->toBe('queued-site-my-queue')
            ->and($second->slug)->toBe('queued-site-my-queue-2');
    });
});

describe('a server without supervisord', function () {
    it('installs supervisor itself rather than telling someone to run apt', function () {
        // The panel has the sudo grant and knows the package name; it installs
        // PHP versions, database engines and fail2ban exactly this way. Being
        // handed a shell command by software that could have run it is a poor
        // answer, even when the command is correct.
        Queue::fake();

        WorkerFake::$supervisorInstalled = false;
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload())
            ->assertStatus(202);

        Queue::assertPushed(InstallSupervisor::class);
    });

    it('does not create the worker while supervisor is still arriving', function () {
        // 202 is "started", not "done". A row here would be a worker the panel
        // lists and supervisord has never heard of.
        Queue::fake();

        WorkerFake::$supervisorInstalled = false;
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload())->assertStatus(202);

        expect(Worker::query()->count())->toBe(0);
    });

    it('does not queue a second apt when one is already running', function () {
        // apt is minutes long and the job is not re-entrant. A double click,
        // or a second person on the same screen, must not stack installs.
        Queue::fake();

        WorkerFake::$supervisorInstalled = false;
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload())->assertStatus(202);
        $this->actingAs($this->admin)->postJson(workerUrl(), workerPayload())->assertStatus(202);

        Queue::assertPushed(InstallSupervisor::class, 1);
    });

    it('refuses to install over a supervisor that is already there', function () {
        // Rather than spending minutes of apt proving what `which` answered.
        Queue::fake();
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl('/install-supervisor'))
            ->assertStatus(422);

        Queue::assertNotPushed(InstallSupervisor::class);
    });

    it('needs the same grant as creating a worker', function () {
        Queue::fake();
        fakeWorkerSupervisor();

        $viewer = User::factory()->create();
        grantPermission($viewer, 'app_worker', view: true, manage: false);

        $this->actingAs($viewer)
            ->postJson(workerUrl('/install-supervisor'))
            ->assertForbidden();
    });

    it('still refuses outright on a path that cannot install for you', function () {
        // Creating now starts an install, but every other caller of apply() —
        // editing a worker, a deploy restarting them — has no such option, and
        // must not write a program file into a directory that is not there.
        // The 422 and its message survive for exactly those.
        $worker = Worker::create([
            'application_id' => $this->application->id,
            'name' => 'Queue worker',
            'command' => 'php artisan queue:work',
            'kind' => 'queue',
            'processes' => 1,
        ]);

        WorkerFake::$supervisorInstalled = false;
        fakeWorkerSupervisor();

        $response = $this->actingAs($this->admin)
            ->putJson(workerUrl('/'.$worker->id), workerPayload(['name' => 'Renamed']))
            ->assertStatus(422);

        // Not a 500, and not the shell's words.
        expect($response->json('message'))->toContain('supervisor');
    });

    it('writes nothing before it refuses', function () {
        // The whole point of checking first. Half a worker on disk, with a row
        // in the database and no program to match, is worse than no worker.
        Queue::fake();

        WorkerFake::$supervisorInstalled = false;
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload())
            ->assertStatus(202);

        expect(collect(WorkerFake::$ran)->filter(fn (string $c): bool => str_contains($c, 'tee')))
            ->toBeEmpty()
            ->and(Worker::query()->count())->toBe(0);
    });

    it('creates normally when supervisord is there', function () {
        // The guard must not be the thing that breaks a working server.
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), [
                'name' => 'Queue worker',
                'command' => 'php artisan queue:work',
                'kind' => 'queue',
                'processes' => 1,
            ])
            ->assertCreated();

        expect(Worker::query()->count())->toBe(1);
    });
});

describe('a server whose sudo grant is out of date', function () {
    /*
     * Reported 2026-09-08, from a panel upgraded past the release that moved
     * workers to supervisord: `sudo -n supervisorctl update` answered "a
     * password is required". supervisord was installed and the program file
     * was written — the grant in /etc/sudoers.d simply predated the binary.
     *
     * `supervisorctl` has been in `server.privilege.binaries` since that
     * release. The file is rewritten by install.sh and by the update's
     * `sync_privileges` step, and that step is deliberately never fatal, so a
     * server can run code whose grant it has not got and say nothing.
     */

    beforeEach(function () {
        // phpunit.xml disables escalation suite-wide. Nothing here exists
        // without it: with sudo off no command is elevated, so none can be
        // refused, and every assertion below would pass against a panel that
        // still reported the old generic failure.
        config()->set('server.privilege.sudo', true);
    });

    it('names the grant rather than the feature', function () {
        $worker = Worker::create([
            'application_id' => $this->application->id,
            'name' => 'Queue worker',
            'command' => 'php artisan queue:work',
            'kind' => 'queue',
            'processes' => 1,
        ]);

        WorkerFake::$denied = ['supervisorctl'];
        fakeWorkerSupervisor();

        $response = $this->actingAs($this->admin)
            ->putJson(workerUrl('/'.$worker->id), workerPayload(['name' => 'Renamed']))
            ->assertStatus(500);

        expect($response->json('code'))->toBe('server_sudo_denied')
            ->and($response->json('message'))->toContain('panel:sudoers')
            // Not "could not control the worker": nothing about the worker is
            // wrong, and that sentence sends the reader to the wrong screen.
            ->and($response->json('message'))->not->toBe(__('errors/application.worker_control_failed'));
    });

    it('does not mistake a refused probe for a missing package', function () {
        // The expensive version of the same confusion. `which` refused and
        // `which` finding nothing are the same exit code, so reading only the
        // status sends a server that already has supervisord to apt — ten
        // minutes of package install that fixes nothing, and the next create
        // fails in exactly the same place.
        Queue::fake();

        WorkerFake::$denied = ['which'];
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload())
            ->assertStatus(500)
            ->assertJsonPath('code', 'server_sudo_denied');

        Queue::assertNotPushed(InstallSupervisor::class);
        expect(Worker::query()->count())->toBe(0);
    });

    it('stops at the refusal instead of reporting the next command', function () {
        // `reload()` is deliberately non-fatal, which is right for supervisord
        // disagreeing with a config and wrong for a grant that will refuse
        // every command after it too. Carrying on turned "the panel may not
        // run supervisorctl" into "the worker would not start" — a true
        // sentence about a worker whose config had never been applied.
        WorkerFake::$denied = ['supervisorctl'];
        fakeWorkerSupervisor();

        $this->actingAs($this->admin)
            ->postJson(workerUrl(), workerPayload())
            ->assertStatus(500);

        $supervisorctl = collect(WorkerFake::$ran)
            ->filter(fn (string $c): bool => str_starts_with($c, 'supervisorctl'));

        expect($supervisorctl->first())->toBe('supervisorctl reread')
            ->and($supervisorctl)->toHaveCount(1);
    });
});
