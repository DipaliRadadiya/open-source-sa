<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ContainerSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * A container that bounces instead of staying up.
 *
 * A crash-looping container is in the running state for part of every cycle, so a single
 * `compose ps --status running` sample can catch it mid-bounce and call the site healthy.
 *
 * The timing qualifier on the restart count keeps this safe: containers that came back after
 * the host went down read `RestartCount 0`, so a fresh container with restarts on the clock
 * has restarted for its own reasons.
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
 * @param  string|list<string>|Closure(int): string  $inspectOut  what `docker inspect`
 *                                                                reports: "<restarts> <startedAt> [<status>]".
 *                                                                A list is answered in order, one entry per
 *                                                                inspect, the last one repeating. A closure is
 *                                                                given the 1-based inspect number — for a loop,
 *                                                                whose count keeps climbing however often asked.
 */
function fakeLoopBox(string $psState, string|array|Closure $inspectOut = '0 '): void
{
    $answers = $inspectOut instanceof Closure ? $inspectOut : (array) $inspectOut;
    $GLOBALS['loopBoxInspects'] = 0;

    Process::fake(function ($process) use ($psState, &$answers) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            $n = ++$GLOBALS['loopBoxInspects'];

            if ($answers instanceof Closure) {
                return Process::result(output: $answers($n));
            }

            return Process::result(output: count($answers) > 1 ? array_shift($answers) : $answers[0]);
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
    // `ps --status running` and `ps --format json` both say running because the sample fell
    // between two crashes; the restart count gives it away. A real loop keeps restarting, so
    // the confirmation sample must see a higher count.
    Sleep::fake();
    fakeLoopBox(
        json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']),
        fn (int $n) => (9 + $n).' '.now()->subSeconds(2)->toIso8601ZuluString().' running',
    );

    try {
        app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');
        $this->fail('a container on its tenth restart must not pass as healthy');
    } catch (ProvisioningFailedException $e) {
        expect($e->reason)->toBe('container_restarting');
    }
});

it('does not punish a long-lived container for restarts in its past', function () {
    // False positive to avoid: `apply()` also runs on a settings save for a long-running site,
    // and a container that crashed once last month carries that count forever.
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
    // This runs AFTER a successful `up`: failing a deploy because a status query did not parse
    // would be the opposite of what the check is for.
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
    // The panel cannot know why an entrypoint exits; it can say where to look.
    $line = __('application.failure_reason.container_restarting', [], 'en');

    expect($line)->toContain('log')->toContain('entrypoint');
});

describe('one restart is not a loop (F2)', function () {
    // A restart count of 1 on a container started seconds ago was read as a loop: a suspicion
    // is looked at again before it decides anything.

    it('lets a container through that restarted once and stayed up', function () {
        Sleep::fake();
        $restarted = '1 '.now()->subSeconds(5)->toIso8601ZuluString().' running';
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), [$restarted, $restarted]);

        // Asked directly: through `apply()` the readiness check's grace window would hide a
        // wrong answer.
        expect(app(ContainerSupervisor::class)->crashLooping($this->application, '/home/loop/loop.test'))->toBeFalse()
            ->and($GLOBALS['loopBoxInspects'])->toBe(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(10)->seconds()]);
    });

    it('calls it a loop when the count went up during the wait', function () {
        Sleep::fake();
        fakeLoopBox(
            json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']),
            fn (int $n) => $n.' '.now()->subSeconds(1)->toIso8601ZuluString().' running',
        );

        expect(fn () => app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test'))
            ->toThrow(ProvisioningFailedException::class);
    });

    it('calls it a loop when the container is no longer running after the wait', function () {
        // The count can stand still while Docker holds the container in its backoff delay;
        // the state says so.
        Sleep::fake();
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), [
            '1 '.now()->subSeconds(5)->toIso8601ZuluString().' running',
            '1 '.now()->subSeconds(5)->toIso8601ZuluString().' restarting',
        ]);

        expect(fn () => app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test'))
            ->toThrow(ProvisioningFailedException::class);
    });

    it('does not wait at all when the first look already shows restarting', function () {
        Sleep::fake();
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'restarting']));

        expect(fn () => app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test'))
            ->toThrow(ProvisioningFailedException::class);

        Sleep::assertNeverSlept();
    });

    it('does not wait for a container that never restarted', function () {
        Sleep::fake();
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), '0 '.now()->subSeconds(5)->toIso8601ZuluString().' running');

        app(ContainerSupervisor::class)->apply($this->application, '/home/loop/loop.test');

        expect($GLOBALS['loopBoxInspects'])->toBe(1);
        Sleep::assertNeverSlept();
    });

    it('treats an unreadable second look as no evidence', function () {
        Sleep::fake();
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), [
            '1 '.now()->subSeconds(5)->toIso8601ZuluString().' running',
            '',
        ]);

        expect(app(ContainerSupervisor::class)->crashLooping($this->application, '/home/loop/loop.test'))->toBeFalse();
    });

    it('takes a recreated container as healthy, not as a loop', function () {
        // `up` can replace the container between the two looks (new one: RestartCount 0).
        // A count that went DOWN is not a restart.
        Sleep::fake();
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), [
            '3 '.now()->subSeconds(5)->toIso8601ZuluString().' running',
            '0 '.now()->subSeconds(1)->toIso8601ZuluString().' running',
        ]);

        expect(app(ContainerSupervisor::class)->crashLooping($this->application, '/home/loop/loop.test'))->toBeFalse();
    });

    it('waits exactly as long as configured, once, however many containers are suspect', function () {
        // Bounded: one wait per check, not one per container and not a poll.
        config(['server.docker.crash_loop_confirm_seconds' => 3]);
        Sleep::fake();
        $restarted = '1 '.now()->subSeconds(5)->toIso8601ZuluString().' running';
        fakeLoopBox(
            json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running'])."\n".json_encode(['Name' => 'sv-app-1-db-1', 'State' => 'running']),
            $restarted,
        );

        expect(app(ContainerSupervisor::class)->crashLooping($this->application, '/home/loop/loop.test'))->toBeFalse();

        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    });

    it('resumes a paused site whose container restarted once', function () {
        Sleep::fake();
        $this->application->forceFill(['disabled_at' => now()])->save();
        $restarted = '1 '.now()->subSeconds(5)->toIso8601ZuluString().' running';
        fakeLoopBox(json_encode(['Name' => 'sv-app-1-app-1', 'State' => 'running']), [$restarted, $restarted]);

        $this->actingAs($this->admin)
            ->postJson("/api/applications/{$this->application->id}/enable")
            ->assertOk();

        expect($this->application->fresh()->disabled_at)->toBeNull();
    });
});
