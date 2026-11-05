<?php

use App\Models\DatabaseConnection;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/**
 * A server without the MySQL/MariaDB client: `env` exits 127. Asking for the
 * version is a probe, so "not installed" is an answer, not a failed command
 * (2026-11-05, the same fix as PostgreSQL's).
 */
beforeEach(function () {
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: "env: 'mysql': No such file or directory")]);
    $this->logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('server-ops')->andReturn($this->logger);
});

function sqlEngineWithoutClient(): SqlEngine
{
    return new SqlEngine(
        new DatabaseConnection([
            'engine' => 'mysql',
            'connection_type' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
        ]),
        app(ServerOps::class),
    );
}

it('answers "no version" without an error in the log', function () {
    expect(sqlEngineWithoutClient()->version())->toBeNull();

    $this->logger->shouldHaveReceived('info')->once()->with('server operation', Mockery::on(
        fn (array $context): bool => $context['op'] === 'query' && $context['exit_code'] === 127 && $context['expected_exit'] === true,
    ));
    $this->logger->shouldNotHaveReceived('error');
});

it('answers "not available" without an error in the log', function () {
    expect(sqlEngineWithoutClient()->available())->toBeFalse();

    $this->logger->shouldNotHaveReceived('error');
});
