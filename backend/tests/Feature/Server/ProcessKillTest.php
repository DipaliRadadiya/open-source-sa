<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    config(['server.protected_services' => ['nginx', 'php8.4-fpm']]);
});

/**
 * Fake `ps -o comm=,user=,ppid= -p <pid>` for one process, and let the kill
 * itself succeed.
 */
function fakeProcess(string $command, string $user = 'deploy', int $ppid = 1000): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $command, $user, $ppid) {
        $runs[] = $process->command;

        return $process->command[0] === 'ps'
            ? Process::result(output: "{$command} {$user} {$ppid}\n")
            : Process::result(exitCode: 0);
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
