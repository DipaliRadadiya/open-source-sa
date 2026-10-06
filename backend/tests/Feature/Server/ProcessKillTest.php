<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    config(['server.protected_services' => ['nginx', 'php8.4-fpm']]);
    Sleep::fake();
});

/**
 * The answer to `ps -o lstart=,stat= -p <pid>`: the process is there until a
 * `kill` has been run, then gone — what a process that honours TERM does.
 */
function startedAnswer(ArrayObject $runs): FakeProcessResult
{
    $killed = collect($runs)->contains(fn ($c) => $c[0] === 'kill');

    return $killed
        ? Process::result(output: '', exitCode: 1)
        : Process::result(output: "Sun Oct  4 10:00:00 2026 S\n");
}

/**
 * Fake `ps -o comm=,user=,ppid= -p <pid>` for one process, and let the kill
 * itself succeed.
 */
function fakeProcess(string $command, string $user = 'deploy', int $ppid = 1000): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $command, $user, $ppid) {
        $runs[] = $process->command;

        if ($process->command[0] !== 'ps') {
            return Process::result(exitCode: 0);
        }

        return in_array('lstart=,stat=', $process->command, true)
            ? startedAnswer($runs)
            : Process::result(output: "{$command} {$user} {$ppid}\n");
    });

    return $runs;
}

function killPid(int $pid, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->deleteJson("/api/server/processes/{$pid}", $body);
}

it('stops a process with SIGTERM by default', function () {
    $runs = fakeProcess('sleep');

    killPid(4321)->assertOk()->assertJsonPath('process.signal', 'TERM');

    // TERM lets the process flush and close its files; KILL does not, which
    // is why it is not what a click defaults to.
    expect(collect($runs))->toContain(['kill', '-TERM', '4321']);
});

it('allows SIGKILL when it is asked for explicitly', function () {
    $runs = fakeProcess('sleep');

    killPid(4321, ['signal' => 'KILL'])->assertOk();

    expect(collect($runs))->toContain(['kill', '-KILL', '4321']);
});

it('rejects any other signal', function () {
    fakeProcess('sleep');

    killPid(4321, ['signal' => 'STOP'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signal');
});

it('refuses to kill PID 1', function () {
    $runs = fakeProcess('systemd', 'root', 0);

    // PID 1 is the init system; killing it panics the kernel.
    killPid(1)->assertUnprocessable();

    expect(collect($runs))->not->toContain(['kill', '-TERM', '1']);
});

it('refuses kernel threads', function () {
    // A child of kthreadd (PID 2) — not a process in the sense the user means.
    $runs = fakeProcess('kworker/0:1', 'root', 2);

    killPid(77)->assertUnprocessable();

    expect(collect($runs))->not->toContain(['kill', '-TERM', '77']);
});

it('refuses a process belonging to a protected service', function () {
    $runs = fakeProcess('nginx', 'root');

    // Protected services can't be stopped from the Services screen; a PID is
    // not a way around that decision.
    killPid(1073)->assertUnprocessable();

    expect(collect($runs))->not->toContain(['kill', '-TERM', '1073']);
});

it('matches the protected php-fpm process despite the unit being spelled differently', function () {
    // systemd calls the unit `php8.4-fpm`; ps reports the process as
    // `php-fpm8.4`. A string or prefix comparison misses this, and a miss
    // means the panel lets you kill the PHP that is serving the request.
    $runs = fakeProcess('php-fpm8.4', 'root');

    killPid(992)->assertUnprocessable();

    expect(collect($runs))->not->toContain(['kill', '-TERM', '992']);
});

it('refuses the panel\'s own process', function () {
    $runs = fakeProcess('php', 'www-data');

    killPid(getmypid())->assertUnprocessable();

    expect(collect($runs))->not->toContain(['kill', '-TERM', (string) getmypid()]);
});

it('reports a PID that is no longer running as not found', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    // Not a quiet success: PIDs get recycled, so "already gone" and "killed
    // whatever now holds that number" must not look the same.
    killPid(999_999)->assertNotFound();
});

it('answers not found for a PID no process can have (bug #8)', function (string $pid) {
    $runs = fakeProcess('sleep');

    // Past PHP_INT_MAX this was a 500: the route promised digits, and the
    // controller asked for an int the value could not fit.
    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->deleteJson("/api/server/processes/{$pid}")
        ->assertNotFound()
        ->assertJsonPath('message', __('errors/process.not_found'));

    expect(collect($runs))->toBeEmpty();
})->with(['99999999999999999999999', '4194305', '0']);

it('reads what it is killing at kill time, not from the request', function () {
    $runs = fakeProcess('sleep');

    killPid(4321)->assertOk()->assertJsonPath('process.command', 'sleep');

    // The table may have been rendered minutes ago; the process could have
    // exited and its PID been reused since.
    expect(collect($runs)->first())->toBe(['ps', '-o', 'comm=,user=,ppid=', '-p', '4321']);
});

it('records what was killed in the activity log', function () {
    fakeProcess('sleep', 'deploy');

    killPid(4321)->assertOk();

    $entry = ActivityLog::where('type', 'server')->where('action', 'process_killed')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['pid'])->toBe(4321)
        ->and($entry->properties['command'])->toBe('sleep');
});

it('denies a user with view-only access', function () {
    fakeProcess('sleep');
    $user = User::factory()->create();
    grantPermission($user, 'dashboard', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/server/processes')->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/server/processes/4321')->assertForbidden();
});

describe('processes inside a unit the server needs', function () {
    // On the nginx test server this screen stopped the SSH daemon and the
    // panel's own queue worker with a 200: neither name is on the configured
    // list, so the name check let both through.
    function fakeProcessInUnit(string $command, string $unit): ArrayObject
    {
        $runs = new ArrayObject;

        Process::fake(function ($process) use ($runs, $command, $unit) {
            $runs[] = $process->command;

            if ($process->command[0] === 'ps') {
                if (in_array('lstart=,stat=', $process->command, true)) {
                    return startedAnswer($runs);
                }

                return in_array('unit=', $process->command, true)
                    ? Process::result(output: "{$unit}\n")
                    : Process::result(output: "{$command} root 1\n");
            }

            return Process::result();
        });

        return $runs;
    }

    it('refuses to stop the SSH daemon', function () {
        $runs = fakeProcessInUnit('sshd', 'ssh.service');

        killPid(1026)->assertUnprocessable();

        expect(collect($runs)->contains(fn ($c) => $c[0] === 'kill'))->toBeFalse();
    });

    it('refuses to stop the panel\'s own queue worker', function () {
        fakeProcessInUnit('php8.4', 'panel-queue.service');

        killPid(2000)->assertUnprocessable();
    });

    it('refuses a process of the web server the Services screen protects', function () {
        fakeProcessInUnit('redis-server', 'redis-server.service');

        killPid(3000)->assertUnprocessable();
    });

    it('still stops a process in a login session or a site app', function () {
        fakeProcessInUnit('sleep', 'session-4.scope');
        killPid(4000)->assertOk();

        fakeProcessInUnit('node', 'sv-app-shop.service');
        killPid(4001)->assertOk();
    });
});

describe('database servers (bug #5)', function () {
    // One click on MariaDB took every site's database offline. Operator's
    // call: refused outright, like SSH; the Services screen restarts them.
    it('refuses a database server by its process name', function (string $command) {
        $runs = fakeProcess($command, 'mysql');

        killPid(5000)->assertUnprocessable()
            ->assertJsonPath('message', __('errors/process.database'));

        expect(collect($runs)->contains(fn ($c) => $c[0] === 'kill'))->toBeFalse();
    })->with(['mariadbd', 'mysqld', 'postgres', 'mongod']);

    it('refuses a database server by its unit, whatever the process is called', function (string $unit) {
        // A PostgreSQL backend is placed by its unit: the cluster's instance
        // part (`18-main`) differs on every server.
        $runs = fakeProcessInUnit('worker', $unit);

        killPid(5001)->assertUnprocessable()
            ->assertJsonPath('message', __('errors/process.database'));

        expect(collect($runs)->contains(fn ($c) => $c[0] === 'kill'))->toBeFalse();
    })->with(['mariadb.service', 'mysql.service', 'postgresql@18-main.service', 'mongod.service']);

    it('still stops a site process whose name only resembles a database', function () {
        fakeProcessInUnit('postgres-exporter', 'sv-app-metrics.service');

        killPid(5002)->assertOk();
    });
});

describe('the operating system\'s own services (bug #5)', function () {
    it('refuses a process in a unit the server runs on', function (string $command, string $unit) {
        $runs = fakeProcessInUnit($command, $unit);

        killPid(6000)->assertUnprocessable()
            ->assertJsonPath('message', __('errors/process.protected'));

        expect(collect($runs)->contains(fn ($c) => $c[0] === 'kill'))->toBeFalse();
    })->with([
        ['dbus-daemon', 'dbus.service'],
        ['cron', 'cron.service'],
        ['systemd-journal', 'systemd-journald.service'],
        ['systemd-resolve', 'systemd-resolved.service'],
        ['rsyslogd', 'rsyslog.service'],
    ]);

    // PK-01 (old QA list): a Dashboard-only role killed fail2ban-server, which
    // the Services screen had refused it, and fail2ban stayed down.
    it('refuses the services that guard or run everything else', function (string $command, string $unit) {
        $runs = fakeProcessInUnit($command, $unit);

        killPid(6000)->assertUnprocessable()
            ->assertJsonPath('message', __('errors/process.protected'));

        expect(collect($runs)->contains(fn ($c) => $c[0] === 'kill'))->toBeFalse();
    })->with([
        ['fail2ban-server', 'fail2ban.service'],
        ['supervisord', 'supervisor.service'],
        ['dockerd', 'docker.service'],
        ['containerd', 'containerd.service'],
    ]);
});

describe('a process that does not exit (bug #6)', function () {
    /**
     * A process that ignores TERM: `ps` keeps finding it, with the same start
     * time, whatever is sent.
     */
    function fakeStubbornProcess(): ArrayObject
    {
        $runs = new ArrayObject;

        Process::fake(function ($process) use ($runs) {
            $runs[] = $process->command;

            if ($process->command[0] !== 'ps') {
                return Process::result();
            }

            return match (true) {
                in_array('lstart=,stat=', $process->command, true) => Process::result(output: "Sun Oct  4 10:00:00 2026 S\n"),
                in_array('unit=', $process->command, true) => Process::result(output: "session-4.scope\n"),
                default => Process::result(output: "sleep deploy 1000\n"),
            };
        });

        return $runs;
    }

    it('says it is still running instead of "stopped"', function () {
        fakeStubbornProcess();

        // 409, so the screen's error path offers Force stop.
        killPid(7000)->assertStatus(409)
            ->assertJsonPath('message', __('errors/process.still_running'));

        expect(ActivityLog::where('action', 'process_killed')->exists())->toBeFalse();
    });

    it('has nothing stronger to offer when KILL does not end it', function () {
        fakeStubbornProcess();

        killPid(7000, ['signal' => 'KILL'])->assertStatus(409)
            ->assertJsonPath('message', __('errors/process.still_running_after_kill'));
    });

    it('treats the PID coming back with another start time as exited', function () {
        // PIDs are recycled: the number now belongs to something else, and the
        // process we signalled is gone.
        $runs = new ArrayObject;

        Process::fake(function ($process) use ($runs) {
            $runs[] = $process->command;
            $killed = collect($runs)->contains(fn ($c) => $c[0] === 'kill');

            return match (true) {
                $process->command[0] !== 'ps' => Process::result(),
                in_array('lstart=,stat=', $process->command, true) => Process::result(
                    output: $killed ? "Sun Oct  4 11:30:00 2026 S\n" : "Sun Oct  4 10:00:00 2026 S\n",
                ),
                in_array('unit=', $process->command, true) => Process::result(output: "session-4.scope\n"),
                default => Process::result(output: "sleep deploy 1000\n"),
            };
        });

        killPid(7001)->assertOk();
    });

    it('treats a zombie as exited', function () {
        // Exited and waiting for its parent; no signal makes that faster.
        $runs = new ArrayObject;

        Process::fake(function ($process) use ($runs) {
            $runs[] = $process->command;
            $killed = collect($runs)->contains(fn ($c) => $c[0] === 'kill');

            return match (true) {
                $process->command[0] !== 'ps' => Process::result(),
                in_array('lstart=,stat=', $process->command, true) => Process::result(
                    output: $killed ? "Sun Oct  4 10:00:00 2026 Z\n" : "Sun Oct  4 10:00:00 2026 S\n",
                ),
                in_array('unit=', $process->command, true) => Process::result(output: "session-4.scope\n"),
                default => Process::result(output: "sleep deploy 1000\n"),
            };
        });

        killPid(7002)->assertOk();
    });
});
