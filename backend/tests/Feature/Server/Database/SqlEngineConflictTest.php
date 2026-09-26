<?php

use App\Jobs\InstallDatabaseEngine;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * MySQL and MariaDB cannot share a server: apt removes one to install the
 * other. The install job refused it, but the screen still offered "Install
 * MySQL" beside a working MariaDB (Apache test server) and the job then
 * failed. The card now says so up front.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

function installedSqlPackages(array $packages): void
{
    Process::fake(function ($process) use ($packages) {
        $command = array_values(array_filter((array) $process->command, fn ($a) => ! in_array($a, ['sudo', '-n'], true)));

        if (($command[0] ?? '') === 'dpkg-query') {
            return Process::result(output: in_array(end($command), $packages, true) ? 'install ok installed' : '');
        }

        return Process::result();
    });
}

function sqlEngineRow(string $engine): array
{
    return collect(test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->getJson('/api/databases/engines')->json('engines'))->firstWhere('engine', $engine);
}

it('does not offer MySQL beside an installed MariaDB, and says why', function () {
    installedSqlPackages(['mariadb-server']);

    $mysql = sqlEngineRow('mysql');

    expect($mysql['installable'])->toBeFalse()
        ->and($mysql['unavailable']['code'])->toBe('engine_conflict')
        ->and($mysql['unavailable']['reason'])->toBe(__('runtime.install_failed.port_in_use_by_mariadb'));
});

it('refuses the install request itself, before any job is queued', function () {
    Queue::fake();
    installedSqlPackages(['mariadb-server']);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/databases/engines/mysql')
        ->assertUnprocessable();

    Queue::assertNotPushed(InstallDatabaseEngine::class);
});

it('still offers the installed engine, whose reinstall repairs its panel account', function () {
    installedSqlPackages(['mariadb-server']);

    expect(sqlEngineRow('mariadb')['unavailable'])->toBeNull();
});

it('offers both on a server with neither', function () {
    installedSqlPackages([]);

    expect(sqlEngineRow('mysql')['unavailable'])->toBeNull()
        ->and(sqlEngineRow('mariadb')['unavailable'])->toBeNull();
});
