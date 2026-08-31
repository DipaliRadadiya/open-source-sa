<?php

use App\Models\User;
use App\Services\Admin\ApiErrorLogWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A throwable whose trace starts in this file rather than in the framework. */
function throwFromHelper(): RuntimeException
{
    return new RuntimeException('boom');
}

beforeEach(function () {
    $this->logDir = storage_path('logs/server-ops-tests-'.getmypid());
    File::deleteDirectory($this->logDir);
    File::makeDirectory($this->logDir, 0755, true);
    config(['logging.channels.server-ops.path' => $this->logDir.'/server-ops.log']);
    Log::forgetChannel('server-ops');
});

afterEach(fn () => File::deleteDirectory($this->logDir));

it('returns safe API errors to an administrator', function () {
    File::put($this->logDir.'/server-ops.log', json_encode([
        'message' => 'api.error',
        'context' => [
            'reference' => 'af04688c-9176-4d96-9d6a-9d15e649bc8a',
            'status' => 500,
            'method' => 'POST',
            'route' => 'api/test/{secret}',
            'exception' => RuntimeException::class,
            'message' => 'Unexpected API error.',
            'user_id' => null,
        ],
        'level_name' => 'ERROR',
        'datetime' => '2026-08-14T12:00:00+00:00',
    ]).PHP_EOL);

    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs')
        ->assertOk()
        ->assertJsonPath('error_logs.0.status', 500)
        ->assertJsonPath('error_logs.0.method', 'POST')
        ->assertJsonPath('error_logs.0.message', 'Unexpected API error.')
        ->assertJsonMissing(['password=secret']);
});

it('records API failures through the server operations channel', function () {
    // The message is the exception's own — this screen exists to identify an
    // error, and it used to record the same seven words for every one of them.
    // But it is still redacted: an exception message carries whatever was in
    // scope when it was thrown, which is how a connection string ends up in a
    // log read by more people than the file it came from.
    $logger = Mockery::mock();
    $logger->shouldReceive('error')
        ->once()
        ->with('api.error', Mockery::on(function (array $context): bool {
            expect($context['status'])->toBe(500)
                ->and($context['message'])->toContain('password=')
                ->and($context['message'])->not->toContain('hunter2')
                // Enough to open the file without publishing the deploy path.
                ->and($context['file'])->toStartWith('tests/Feature/Admin/ApiErrorLogTest.php:');

            return true;
        }));
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);

    app(ApiErrorLogWriter::class)->record(
        new RuntimeException('password=hunter2'),
        Request::create('/api/test/{secret}', 'POST'),
    );
});

it('records where the exception came from, in application frames only', function () {
    // The top of a Laravel stack is the same handler chain every time and says
    // nothing about this failure; the first frames inside the application are
    // what identify it.
    $captured = null;

    $logger = Mockery::mock();
    $logger->shouldReceive('error')->once()->with('api.error', Mockery::on(function (array $context) use (&$captured): bool {
        $captured = $context;

        return true;
    }));
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);

    app(ApiErrorLogWriter::class)->record(
        throwFromHelper(),
        Request::create('/api/test', 'GET'),
    );

    expect($captured['trace'])->not->toBeEmpty()
        ->and(collect($captured['trace'])->every(fn (string $f): bool => ! str_contains($f, '/vendor/')))->toBeTrue()
        ->and($captured['trace'][0])->toContain('ApiErrorLogTest.php');
});

it('shows failed server operations and filters them by reference', function () {
    $firstReference = '2d44e941-0f2f-4d6a-a4fb-5f2c13ac99f5';
    $secondReference = '3f734d8d-ae5c-4ae2-815d-a7822eaa2b97';
    File::put($this->logDir.'/server-ops.log', implode(PHP_EOL, [
        json_encode([
            'message' => 'server operation',
            'context' => [
                'reference' => $firstReference,
                'feature' => 'firewall',
                'op' => 'apply',
                'exit_code' => 1,
                'stderr' => 'ufw: password=secret-value denied',
                'actor_id' => 7,
            ],
            'level_name' => 'ERROR',
            'datetime' => '2026-08-14T12:00:00+00:00',
        ]),
        json_encode([
            'message' => 'server operation',
            'context' => ['reference' => $secondReference, 'feature' => 'cronjob', 'actor_id' => 8],
            'level_name' => 'ERROR',
            'datetime' => '2026-08-14T12:01:00+00:00',
        ]),
        '',
    ]));

    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/admin/error-logs?reference={$firstReference}")
        ->assertOk()
        ->assertJsonCount(1, 'error_logs')
        ->assertJsonPath('error_logs.0.reference', $firstReference)
        ->assertJsonPath('error_logs.0.message', 'Server operation failed.')
        ->assertJsonPath('error_logs.0.feature', 'firewall')
        ->assertJsonPath('error_logs.0.operation', 'apply')
        ->assertJsonPath('error_logs.0.exit_code', 1)
        ->assertJsonPath('error_logs.0.error', 'ufw: password=*** denied')
        ->assertJsonPath('error_logs.0.user_id', 7);
});

it('falls back to redacted stdout when a server operation has no stderr', function () {
    $stdoutReference = '5c3cc1bb-3f01-4c2c-8c3c-9fe15b675b63';
    $stderrReference = '9c01c412-493a-453c-8d91-70c6fa8f82e4';
    $emptyReference = '13e01e85-4e82-40a5-9976-791d31486762';

    File::put($this->logDir.'/server-ops.log', implode(PHP_EOL, [
        json_encode([
            'message' => 'server operation',
            'context' => [
                'reference' => $stdoutReference,
                'feature' => 'application',
                'op' => 'installer.install_app',
                'exit_code' => 1,
                'stderr' => '',
                'stdout' => 'Moodle failed: password=secret-value token=abc123',
            ],
            'level_name' => 'ERROR',
            'datetime' => '2026-08-27T13:00:00+00:00',
        ]),
        json_encode([
            'message' => 'server operation',
            'context' => [
                'reference' => $stderrReference,
                'feature' => 'application',
                'op' => 'installer.install_app',
                'exit_code' => 1,
                'stderr' => 'The stderr reason',
                'stdout' => 'The less specific stdout output',
            ],
            'level_name' => 'ERROR',
            'datetime' => '2026-08-27T13:01:00+00:00',
        ]),
        json_encode([
            'message' => 'server operation',
            'context' => [
                'reference' => $emptyReference,
                'feature' => 'application',
                'op' => 'installer.install_app',
                'exit_code' => 1,
                'stderr' => ' ',
                'stdout' => '',
            ],
            'level_name' => 'ERROR',
            'datetime' => '2026-08-27T13:02:00+00:00',
        ]),
        '',
    ]));

    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)
        ->getJson("/api/admin/error-logs?reference={$stdoutReference}")
        ->assertOk()
        ->assertJsonPath('error_logs.0.error', 'Moodle failed: password=*** token=***');

    $this->withHeaders($headers)
        ->getJson("/api/admin/error-logs?reference={$stderrReference}")
        ->assertOk()
        ->assertJsonPath('error_logs.0.error', 'The stderr reason');

    $this->withHeaders($headers)
        ->getJson("/api/admin/error-logs?reference={$emptyReference}")
        ->assertOk()
        ->assertJsonPath('error_logs.0.error', null);
});

it('sends the command that failed, with how long it ran and how often it was tried', function () {
    // Recorded since the feature shipped and never sent, so the screen showed
    // that something broke without ever showing what. The command was already
    // redacted where it was written -- ServerOps puts every command line
    // through CommandRedactor before logging it -- so this is the same text an
    // operator would read on the box, without having to go to the box.
    File::put($this->logDir.'/server-ops.log', json_encode([
        'message' => 'server operation',
        'context' => [
            'reference' => 'c0ffee00-0000-4000-8000-000000000001',
            'feature' => 'application',
            'op' => 'create_database',
            'command' => 'mysql --user=root --password=[REDACTED] -e CREATE DATABASE shop',
            'exit_code' => 1,
            'stderr' => 'ERROR 2002 (HY000): Can\'t connect to local MySQL server',
            'duration_ms' => 12043,
            'attempts' => 3,
        ],
        'level_name' => 'ERROR',
        'datetime' => '2026-08-31T07:00:00+00:00',
    ]).PHP_EOL);

    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $entry = collect($this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs')->assertOk()->json('error_logs'))
        ->firstWhere('reference', 'c0ffee00-0000-4000-8000-000000000001');

    expect($entry)->not->toBeNull()
        ->and($entry['command'])->toContain('CREATE DATABASE shop')
        // Redacted at the point it was written, and still redacted here.
        ->and($entry['command'])->not->toContain('hunter2')
        ->and($entry['duration_ms'])->toBe(12043)
        // Three attempts and twelve seconds is a lock being retried; one
        // attempt and 40ms is something else entirely, and the timestamps
        // alone cannot tell those apart.
        ->and($entry['attempts'])->toBe(3);
});

it('rejects an invalid reference filter', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs?reference=not-a-reference')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reference');
});

it('does not record expected API client errors', function () {
    $request = Request::create('/api/test', 'GET');
    app(ApiErrorLogWriter::class)->record(new NotFoundHttpException, $request);

    expect(glob($this->logDir.'/*') ?: [])->toBe([]);
});

it('denies non-admins', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs')
        ->assertForbidden();
});
