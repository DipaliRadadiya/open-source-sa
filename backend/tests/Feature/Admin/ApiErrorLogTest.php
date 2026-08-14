<?php

use App\Models\User;
use App\Services\Admin\ApiErrorLogWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->logDir = storage_path('logs/api-error-tests-'.getmypid());
    File::deleteDirectory($this->logDir);
    File::makeDirectory($this->logDir, 0755, true);
    config(['logging.channels.api-errors.path' => $this->logDir.'/api-errors.log']);
    Log::forgetChannel('api-errors');
});

afterEach(fn () => File::deleteDirectory($this->logDir));

it('returns safe API errors to an administrator', function () {
    $request = Request::create('/api/test/{secret}', 'POST');
    app(ApiErrorLogWriter::class)->record(new RuntimeException('password=secret'), $request);

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

it('does not record expected API client errors', function () {
    $request = Request::create('/api/test', 'GET');
    app(ApiErrorLogWriter::class)->record(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(), $request);

    expect(glob($this->logDir.'/*') ?: [])->toBe([]);
});

it('denies non-admins', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/error-logs')
        ->assertForbidden();
});
