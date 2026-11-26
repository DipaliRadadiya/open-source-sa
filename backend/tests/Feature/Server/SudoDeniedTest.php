<?php

use App\Exceptions\Server\Application\FileOperationException;
use App\Models\User;
use App\Services\Server\ServerOps;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/*
 * `sudo: a password is required`: the panel has no sudoers grant for a binary, because
 * /etc/sudoers.d predates the release that added it. Unlike other failures, nothing ran,
 * nothing is broken, and retrying can never help.
 */

beforeEach(function () {
    config()->set('server.transient.attempts', 1);
    config()->set('server.transient.delay_ms', 0);
    // phpunit.xml turns escalation off for the suite; with it off sudo can never refuse and
    // this file would assert nothing. The shape under test only exists on a server that elevates.
    config()->set('server.privilege.sudo', true);
    $this->ops = app(ServerOps::class);
});

/** What sudo actually prints, with the trailing newline it actually has. */
function denial(string $line = 'sudo: a password is required'): FakeProcessResult
{
    return Process::result(errorOutput: $line."\n", exitCode: 1);
}

it('recognises the three ways sudo refuses a command', function (string $stderr) {
    Process::fake(fn () => denial($stderr));

    expect($this->ops->run(['useradd', '-m', 'deploy'])->denied)->toBeTrue();
})->with([
    'no password' => 'sudo: a password is required',
    'no askpass' => 'sudo: no tty present and no askpass program specified',
    'not permitted' => 'Sorry, user panel is not allowed to execute \'/usr/bin/supervisorctl update\' as root on box.',
    'not a sudoer' => 'panel is not in the sudoers file. This incident will be reported.',
]);

it('is not busy, and is not retried', function () {
    config()->set('server.transient.attempts', 3);
    $attempts = 0;

    Process::fake(function () use (&$attempts) {
        $attempts++;

        return denial();
    });

    $result = $this->ops->run(['useradd', '-m', 'deploy']);

    // Retrying a refusal only delays the same answer three times over.
    expect($attempts)->toBe(1)
        ->and($result->denied)->toBeTrue()
        ->and($result->busy)->toBeFalse()
        ->and($result->staleLock)->toBeFalse();
});

it('is not read as an answer', function () {
    Process::fake(fn () => denial());

    // `test -f` exits 1 for "no" and sudo exits 1 for "you may not ask": a guard that cannot
    // tell them apart reports a file as absent.
    $result = $this->ops->run(['test', '-f', '/etc/passwd'], expectedExitCodes: [1]);

    expect($result->answered)->toBeFalse()
        ->and($result->denied)->toBeTrue();
});

it('does not claim a privilege fault for a command it never elevated', function () {
    // `git` is not in server.privilege.binaries, so this never ran under sudo; blaming a
    // stale grant would send the operator to the wrong file.
    Process::fake(fn () => Process::result(
        errorOutput: "fatal: could not read Username: a password is required\n",
        exitCode: 128,
    ));

    expect($this->ops->run(['git', 'fetch'])->denied)->toBeFalse();
});

it('does not claim a privilege fault when a privileged tool merely prints the words', function () {
    // stderr from mysql itself, after sudo let it run: only lines prefixed with sudo's own name count.
    Process::fake(fn () => Process::result(
        errorOutput: "ERROR 1045 (28000): Access denied for user 'root'@'localhost' (a password is required)\n",
        exitCode: 1,
    ));

    expect($this->ops->run(['mysql', '-e', 'SELECT 1'])->denied)->toBeFalse();
});

it('names the cause on the admin error dashboard', function () {
    $dir = storage_path('logs/sudo-denied-'.getmypid());
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0755, true);
    config(['logging.channels.server-ops.path' => $dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');

    Process::fake(fn () => denial());

    $this->ops->run(['supervisorctl', 'update'], ['feature' => 'application', 'op' => 'worker_update']);

    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    // "Server operation failed." is ApiErrorLogResource's fallback for an entry whose context
    // carries no message; the entry must say what to do instead.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs')
        ->assertOk()
        ->assertJsonPath('error_logs.0.operation', 'worker_update')
        ->assertJsonPath('error_logs.0.message', __('errors/server.sudo_denied'));

    File::deleteDirectory($dir);
});

it('renders the repair instead of the feature\'s own message', function () {
    $exception = new FileOperationException('ref-1', denied: true);

    $response = $exception->render(request());
    $payload = $response->getData(true);

    expect($payload['message'])->toBe(__('errors/server.sudo_denied'))
        ->and($payload['message'])->toContain('panel:sudoers')
        // Not "the file operation failed": no file operation was attempted.
        ->and($payload['message'])->not->toBe(__('errors/application.file_operation_failed'))
        ->and($payload['code'])->toBe('server_sudo_denied')
        // A fault needing a human, not a retry, so not the 503 that busy carries.
        ->and($response->getStatusCode())->toBe(500);
});

it('still reports busy and stale locks as themselves', function () {
    $busy = (new FileOperationException('ref-2', busy: true))->render(request());
    $stale = (new FileOperationException('ref-3', staleLock: true))->render(request());

    expect($busy->getData(true)['code'])->toBe('server_busy')
        ->and($busy->getStatusCode())->toBe(503)
        ->and($stale->getData(true)['code'])->toBe('server_stale_lock');
});

it('keeps a refused probe on the admin error dashboard', function () {
    $dir = storage_path('logs/sudo-denied-probe-'.getmypid());
    File::deleteDirectory($dir);
    File::makeDirectory($dir, 0755, true);
    config(['logging.channels.server-ops.path' => $dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');

    Process::fake(fn () => denial());

    // Exit 1 is "no" for `test -f` and also what sudo exits with when it refuses. Probes log
    // their "no" as info; a refusal must not ride along, or a stale grant vanishes from the screen.
    $this->ops->probe(['test', '-f', '/etc/passwd'], ['feature' => 'application', 'op' => 'env_locate']);

    $token = User::factory()->admin()->create()->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs')
        ->assertOk()
        ->assertJsonPath('error_logs.0.operation', 'env_locate');

    File::deleteDirectory($dir);
});
