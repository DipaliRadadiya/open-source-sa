<?php

use App\Models\User;
use App\Services\Server\EnvFile;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-redis-'.getmypid();
    File::deleteDirectory($this->dir);
    File::makeDirectory($this->dir, 0755, true);

    $this->redisCli = $this->dir.'/redis-cli';
    File::put($this->redisCli, '');

    $this->envPath = $this->dir.'/.env';
    File::put($this->envPath, "APP_NAME=Panel\n# a comment operators edit by hand\nREDIS_PASSWORD=null\nQUEUE_CONNECTION=database\n");

    config(['server.redis_cli' => $this->redisCli, 'database.redis.default.password' => null]);
    app()->useEnvironmentPath($this->dir);
});

afterEach(function () {
    app()->useEnvironmentPath(base_path());
    File::deleteDirectory($this->dir);
});

/**
 * @param  array<int, string>  $failing  redis-cli sub-commands that should fail
 */
function fakeRedis(array $failing = []): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs, $failing) {
        $runs[] = ['command' => $process->command, 'input' => $process->input, 'env' => $process->environment ?? []];
        $command = implode(' ', $process->command);

        foreach ($failing as $fail) {
            if (str_contains($command, $fail)) {
                return Process::result(exitCode: 1, errorOutput: 'NOAUTH Authentication required.');
            }
        }

        return match (true) {
            str_contains($command, 'config get requirepass') => Process::result(output: "requirepass\n\n"),
            str_contains($command, 'config get maxmemory-policy') => Process::result(output: "maxmemory-policy\nnoeviction\n"),
            str_contains($command, 'config get maxmemory') => Process::result(output: "maxmemory\n0\n"),
            str_contains($command, 'ping') => Process::result(output: "PONG\n"),
            default => Process::result(exitCode: 0),
        };
    });

    return $runs;
}

/**
 * Only the calls that *change* the password — `config get requirepass` also
 * mentions it, and counting that would make every assertion below off by one.
 */
function passwordWrites(ArrayObject $runs): Collection
{
    return collect($runs)
        ->filter(fn (array $r) => in_array('set', $r['command'], true) && in_array('requirepass', $r['command'], true))
        ->values();
}

/**
 * The credential swap runs after the response, so the assertions have to run
 * the terminating callbacks the request registered — the same thing php-fpm
 * does once the response is flushed.
 */
function applyDeferred(): void
{
    app()->terminate();
}

function saveRedis(array $body): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/redis', array_merge([
            'maxmemory' => '0', 'maxmemory_policy' => 'noeviction',
        ], $body));
}

function saveRedisAndApply(array $body): TestResponse
{
    $response = saveRedis($body);
    applyDeferred();

    return $response;
}

it('never puts the password in a command argument', function () {
    $runs = fakeRedis();

    saveRedisAndApply(['password' => 'sup3r-secret-value'])->assertStatus(202);

    // `ps` is world readable. Every argument of every redis-cli call is
    // therefore public, so the secret goes on stdin instead.
    foreach ($runs as $run) {
        expect(implode(' ', $run['command']))->not->toContain('sup3r-secret-value');
    }

    $set = passwordWrites($runs)->first();
    expect($set['command'])->toContain('-x')
        ->and($set['input'])->toBe('sup3r-secret-value');
});

it('authenticates the rewrite from the environment, not from argv', function () {
    $runs = fakeRedis();

    saveRedisAndApply(['password' => 'sup3r-secret-value'])->assertStatus(202);

    // Two redis-cli processes means two connections, and the second arrives
    // after auth is required — which is why the rewrite used to fail and the
    // password was never persisted to redis.conf.
    // The memory settings are rewritten too; the one that matters is the
    // rewrite carrying the new credential.
    $rewrite = collect($runs)->last(fn (array $r) => in_array('rewrite', $r['command'], true));

    expect($rewrite['env'])->toBe(['REDISCLI_AUTH' => 'sup3r-secret-value'])
        ->and($rewrite['command'])->not->toContain('-a');
});

it('records the password in the panel own env file', function () {
    fakeRedis();

    saveRedisAndApply(['password' => 'sup3r-secret-value'])->assertStatus(202);

    // Without this the panel locks itself out of its own cache and session
    // store the moment those live on Redis.
    expect(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('sup3r-secret-value');
});

it('keeps the rest of the env file exactly as it was', function () {
    fakeRedis();

    saveRedisAndApply(['password' => 'sup3r-secret-value'])->assertStatus(202);

    $contents = File::get($this->envPath);

    // Operators hand-edit this file. Rewriting it from a template would drop
    // their comments and ordering without saying so.
    expect($contents)->toContain('APP_NAME=Panel')
        ->toContain('# a comment operators edit by hand')
        ->toContain('QUEUE_CONNECTION=database');
});

it('refuses before touching redis when it cannot record the password', function () {
    $runs = fakeRedis();
    File::delete($this->envPath);

    saveRedis(['password' => 'sup3r-secret-value'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    // Checked first, not after: setting a password we cannot write down is
    // exactly how the panel locks itself out.
    expect(passwordWrites($runs))->toBeEmpty();
});

it('tells the frontend when the password cannot be managed', function () {
    fakeRedis();
    File::delete($this->envPath);

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->json('settings.redis');

    // Disable the control rather than offer it and then refuse.
    expect($settings['password_manageable'])->toBeFalse();
});

it('puts the old password back when the change cannot be verified', function () {
    $runs = fakeRedis(failing: ['ping']);

    saveRedisAndApply(['password' => 'sup3r-secret-value']);

    // Redis is already on the new password at this point; leaving it there
    // with nothing recorded is the lockout. So it goes back.
    $rollback = passwordWrites($runs);

    expect($rollback)->toHaveCount(2)
        ->and($rollback[1]['input'])->toBe('')
        ->and($rollback[1]['env'])->toBe(['REDISCLI_AUTH' => 'sup3r-secret-value']);

    // And nothing was written, so the file still matches the live server.
    expect(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('null');
});

it('rolls back when the password cannot be persisted to redis conf', function () {
    $runs = fakeRedis(failing: ['rewrite']);

    saveRedisAndApply(['password' => 'sup3r-secret-value']);

    // A password Redis forgets on restart is worse than no password: the
    // panel would hold a credential the server stops asking for.
    expect(passwordWrites($runs))->toHaveCount(2)
        ->and(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('null');
});

it('logs a traceable failure, since nothing can reach the caller by then', function () {
    $log = storage_path('logs/server-ops.log');
    $before = is_file($log) ? (string) File::get($log) : '';

    fakeRedis(failing: ['ping']);

    // The response has already been sent when this runs, so a 500 is not
    // available — the failure has to be findable afterwards instead.
    saveRedisAndApply(['password' => 'sup3r-secret-value'])->assertStatus(202);

    $written = substr((string) File::get($log), strlen($before));

    expect($written)->toContain('redis password could not be verified')
        // The credential itself must never reach a log file.
        ->and($written)->not->toContain('sup3r-secret-value');
});

it('answers 202 rather than claiming the password is already set', function () {
    fakeRedis();

    $response = saveRedis(['password' => 'sup3r-secret-value'])->assertStatus(202);

    // Returning the group here would report has_password from a Redis that
    // has not been changed yet — true in a moment, false right now.
    expect($response->json('message'))->not->toBeEmpty()
        ->and($response->json())->not->toHaveKey('redis');
});

it('leaves the password alone when the field is absent', function () {
    $runs = fakeRedis();

    saveRedisAndApply([])->assertOk();

    // Saving the memory settings must not clear the password — the read side
    // never returns it, so an unchanged form has nothing to send back.
    expect(passwordWrites($runs))->toBeEmpty()
        ->and(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('null');
});

it('never returns the password itself', function () {
    fakeRedis();

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->json('settings.redis');

    expect($settings)->not->toHaveKey('password')
        ->and(array_keys($settings))->toContain('has_password');
});

it('keeps the env file permissions it found', function () {
    fakeRedis();
    chmod($this->envPath, 0664);

    saveRedisAndApply(['password' => 'sup3r-secret-value'])->assertStatus(202);

    // Imposing a mode here is how the panel locks itself out of its own
    // config: force 0640 on a group-writable file and the first write
    // succeeds while every write after it is refused. Caught live.
    expect(fileperms($this->envPath) & 0777)->toBe(0664)
        ->and(is_writable($this->envPath))->toBeTrue();
});

it('can change the password twice in a row', function () {
    fakeRedis();
    chmod($this->envPath, 0664);

    saveRedisAndApply(['password' => 'first-password-value'])->assertStatus(202);
    expect(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('first-password-value');

    // The regression this pins: the second attempt is what failed, because
    // the first had quietly made the file unwritable.
    saveRedisAndApply(['password' => 'second-password-val'])->assertStatus(202);
    expect(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('second-password-val');
});

it('authenticates with the current password when replacing one', function () {
    $runs = fakeRedis();
    // A password is already in force.
    config(['database.redis.default.password' => 'the-existing-password']);
    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => $process->input, 'env' => $process->environment ?? []];
        $command = implode(' ', $process->command);

        return match (true) {
            str_contains($command, 'config get requirepass') => Process::result(output: "requirepass\nthe-existing-password\n"),
            str_contains($command, 'ping') => Process::result(output: "PONG\n"),
            default => Process::result(exitCode: 0),
        };
    });

    saveRedisAndApply(['password' => 'a-brand-new-password'])->assertStatus(202);

    // Replacing a password needs the old one; unauthenticated, Redis answers
    // NOAUTH and the change silently does nothing. Caught live: the first
    // password applied and every change after it was lost.
    $set = passwordWrites($runs)->first();

    expect($set['env'])->toBe(['REDISCLI_AUTH' => 'the-existing-password'])
        ->and($set['input'])->toBe('a-brand-new-password');
});

it('clears the password only when explicitly asked', function () {
    $runs = fakeRedis();
    chmod($this->envPath, 0664);

    // An empty string never reaches validation intact —
    // ConvertEmptyStringsToNull rewrites it to null, which is the same shape
    // as "field omitted". So removal needs its own flag or it is impossible
    // to express. Found live: a password could be set and never taken off.
    saveRedisAndApply(['password' => ''])->assertOk();
    expect(passwordWrites($runs))->toBeEmpty();

    $runs = fakeRedis();
    saveRedisAndApply(['remove_password' => true])->assertStatus(202);

    expect(passwordWrites($runs)->first()['input'])->toBe('')
        ->and(app(EnvFile::class)->get('REDIS_PASSWORD'))->toBe('null');
});
