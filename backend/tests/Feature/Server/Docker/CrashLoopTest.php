<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ContainerSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A container that bounces instead of staying up.
 *
 * `compose ps --status running` was the only check, and a crash-looping container
 * is in the running state for part of every cycle — so one sample can catch it
 * mid-bounce and call the site healthy. Measured on a real box with
 * `curlimages/curl`, which prints its usage and exits: the panel reported the site
 * **active** while the container was on its tenth restart, and the site answered
 * 502.
 *
 * The timing qualifier on the restart count is what keeps this safe, and it was
 * measured rather than reasoned: containers that came back after the host went
 * down read `RestartCount 0`, so a fresh container with restarts on the clock has
 * restarted for its own reasons.
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

    $this->systemUser = SystemUser::create(['username' => 'loop', 'home_path' => '/home/loop']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Loop', 'slug' => 'loop', 'domain' => 'loop.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'curlimages/curl:8.11.0', 'container_port' => 80, 'app_port' => 20001,
        'status' => 'active',
    ]);
});

/**
 * @param  string  $psState  what `compose ps --format json` reports.
 * @param  string  $inspectOut  what `docker inspect` reports: "<restarts> <startedAt>".
 */
function fakeLoopBox(string $psState, string $inspectOut = '0 '): void
{
    Process::fake(function ($process) use ($psState, $inspectOut) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            return Process::result(output: $inspectOut);
        }

        // The liveness sample, which a bouncing container passes.
        if (in_array('--status', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        if (in_array('--format', $args, true) && in_array('json', $args, true)) {
            return Process::result(output: $psState);
        }

        return Process::result(exitCode: 0);
    });
}

it('refuses to call a restarting container a running site', function () {
    fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'restarting']));

    try {
        app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');
        $this->fail('a bouncing container must not pass as healthy');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('container_restarting')
            ->and($e->reason)->toBe('container_restarting');
    }
});

it('catches the loop even when the sample lands on a running moment', function () {
    // The actual failure: `ps --status running` answers, and `ps --format json` says
    // running too, because the sample happened between two crashes. The restart
    // count is what gives it away.
    fakeLoopBox(
        json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']),
        '10 '.now()->subSeconds(20)->toIso8601ZuluString(),
    );

    try {
        app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');
        $this->fail('a container on its tenth restart must not pass as healthy');
    } catch (ProvisioningFailedException $e) {
        expect($e->reason)->toBe('container_restarting');
    }
});

it('does not punish a long-lived container for restarts in its past', function () {
    // The false positive this has to avoid. `apply()` also runs on a settings save
    // for a site that has been up for weeks; a container that crashed once last
    // month carries that count forever, and failing the save for it would turn a
    // working site into a reported failure.
    fakeLoopBox(
        json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']),
        '3 '.now()->subDays(9)->toIso8601ZuluString(),
    );

    app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');
})->throwsNoExceptions();

it('lets a healthy container through', function () {
    fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), '0 ');

    app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');
})->throwsNoExceptions();

it('treats unreadable status output as healthy, not as a failure', function () {
    // This runs AFTER a successful `up`. Failing a deploy because a status query
    // did not parse would be the opposite of the mistake it exists to prevent.
    fakeLoopBox("not json at all\n");

    app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');
})->throwsNoExceptions();

it('has the reason translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('application.failure_reason.container_restarting', [], $locale);

        expect($line)->not->toBe('application.failure_reason.container_restarting')
            ->and($line)->not->toBeEmpty();
    }
});

it('points at the container log, which is the only place the answer is', function () {
    // The panel cannot know why somebody's entrypoint exits. What it can do is say
    // where to look instead of leaving a bare failure.
    $line = __('application.failure_reason.container_restarting', [], 'en');

    expect($line)->toContain('log')->toContain('entrypoint');
});
