<?php

use App\Models\User;
use App\Services\Admin\ApiErrorLogWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    $logger = Mockery::mock();
    $logger->shouldReceive('error')
        ->once()
        ->with('api.error', Mockery::on(fn (array $context) => $context['status'] === 500
            && $context['message'] === 'Unexpected API error.'));
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);

    app(ApiErrorLogWriter::class)->record(
        new RuntimeException('password=secret'),
        Request::create('/api/test/{secret}', 'POST'),
    );
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
