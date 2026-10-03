<?php

use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/*
 * Bug #48: the admin Error Log was full of ordinary "no" answers. Asking
 * whether MySQL is there, on a server with MariaDB only, logged "Access
 * denied for user 'root'" as an error on every screen that asked; asking
 * whether a .env exists logged `test -f` exiting 1. Measured on the test
 * servers: those two alone were most of the dashboard.
 */

beforeEach(function () {
    $this->dir = storage_path('logs/expected-answers-'.getmypid());
    File::deleteDirectory($this->dir);
    File::makeDirectory($this->dir, 0755, true);
    config(['logging.channels.server-ops.path' => $this->dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');

    $this->token = User::factory()->admin()->create()->createToken('test')->plainTextToken;
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function dashboardErrors(): array
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/admin/error-logs')
        ->assertOk()
        ->json('error_logs');
}

function mysqlEngine(): SqlEngine
{
    return new SqlEngine(new DatabaseConnection([
        'engine' => 'mysql', 'connection_type' => 'tcp', 'host' => '127.0.0.1',
        'port' => 3306, 'username' => 'root',
    ]), app(ServerOps::class));
}

it('does not put "is MySQL there?" answered no on the dashboard', function () {
    Process::fake(fn () => Process::result(
        errorOutput: "ERROR 1698 (28000): Access denied for user 'root'@'localhost'\n",
        exitCode: 1,
    ));

    expect(mysqlEngine()->available())->toBeFalse()
        ->and(mysqlEngine()->version())->toBeNull()
        ->and(dashboardErrors())->toBe([]);
});

it('still reports a real query failing', function () {
    Process::fake(fn () => Process::result(errorOutput: "ERROR 1044 (42000): Access denied\n", exitCode: 1));

    mysqlEngine()->listDatabases();

    expect(dashboardErrors())->toHaveCount(1);
});

it('does not put a file that is not there on the dashboard', function () {
    Process::fake(fn () => Process::result(exitCode: 1));

    expect(app(ServerOps::class)->probe(['test', '-f', '/home/site/.env'], ['op' => 'env_locate'])->ok)->toBeFalse()
        ->and(dashboardErrors())->toBe([]);
});
