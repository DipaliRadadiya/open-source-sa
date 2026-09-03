<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
 * Binary log retention and size.
 *
 * The risk this screen manages is a disk filling with logs nothing expires,
 * which does not degrade the database — it takes the machine down. So the
 * tests care about two things: that a retention value is actually persisted
 * (a setting that vanishes on restart is worse than none, because it was
 * believed), and that purging goes through the server rather than the
 * filesystem.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-binlog-'.uniqid();
    File::ensureDirectoryExists($this->dir);

    config([
        'server.databases.engines.mariadb.config_dir' => $this->dir,
        'server.databases.auth_file_dir' => sys_get_temp_dir(),
    ]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function fakeBinlog(
    string $logBin = 'ON',
    int $expireSeconds = 604800,
    int $maxSize = 104857600,
    ?string $expireDays = null,
): void {
    Process::fake(function ($process) use ($logBin, $expireSeconds, $maxSize, $expireDays) {
        $cmd = $process->command;

        if (($cmd[0] ?? null) === 'sudo' && ($cmd[1] ?? null) === '-n') {
            $cmd = array_slice($cmd, 2);
        }

        if (($cmd[0] ?? '') === 'tee') {
            File::put($cmd[1], (string) $process->input);

            return Process::result(exitCode: 0);
        }

        if (($cmd[0] ?? '') === 'cat') {
            return is_file($cmd[1] ?? '')
                ? Process::result(output: File::get($cmd[1]))
                : Process::result(exitCode: 1, errorOutput: 'No such file');
        }

        $sql = (string) $process->input;

        return match (true) {
            str_contains($sql, "LIKE 'log_bin'") => Process::result(output: "log_bin\t{$logBin}\n"),
            str_contains($sql, "LIKE 'binlog_format'") => Process::result(output: "binlog_format\tROW\n"),
            str_contains($sql, "LIKE 'binlog_expire_logs_seconds'") => $expireDays !== null
                // Stand in for an older MariaDB: the modern variable is absent.
                ? Process::result(output: '')
                : Process::result(output: "binlog_expire_logs_seconds\t{$expireSeconds}\n"),
            str_contains($sql, "LIKE 'expire_logs_days'") => $expireDays === null
                ? Process::result(output: '')
                : Process::result(output: "expire_logs_days\t{$expireDays}\n"),
            str_contains($sql, "LIKE 'max_binlog_size'") => Process::result(output: "max_binlog_size\t{$maxSize}\n"),
            str_contains($sql, 'SHOW BINARY LOGS') => $logBin === 'ON'
                ? Process::result(output: "mysql-bin.000001\t1048576\nmysql-bin.000002\t2097152\n")
                : Process::result(exitCode: 1, errorOutput: 'binary logging not enabled'),
            str_contains($sql, 'SET GLOBAL') => Process::result(output: ''),
            str_contains($sql, 'PURGE BINARY LOGS') => Process::result(output: ''),
            default => Process::result(output: '1'),
        };
    });
}

function putBinlog(array $payload): TestResponse
{
    return test()
        ->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/mysql-binlog', $payload);
}

it('persists retention to its own drop-in so it survives a restart', function () {
    fakeBinlog();

    putBinlog(['expire_seconds' => 259200, 'max_binlog_size' => 104857600])->assertOk();

    $dropIn = $this->dir.'/99-serveravatar-binlog.cnf';

    expect(File::exists($dropIn))->toBeTrue()
        ->and(File::get($dropIn))->toContain('binlog_expire_logs_seconds = 259200')
        ->and(File::get($dropIn))->toContain('max_binlog_size = 104857600');
});

it('applies retention to the running server, so no restart is needed', function () {
    fakeBinlog();

    putBinlog(['expire_seconds' => 259200, 'max_binlog_size' => 104857600])->assertOk();

    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'SET GLOBAL binlog_expire_logs_seconds = 259200'));
    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'SET GLOBAL max_binlog_size = 104857600'));
});

it('keeps its keys out of the connection limit drop-in', function () {
    // MysqlSettings rewrites 99-serveravatar.cnf wholesale. If these keys
    // lived there, saving the connection limit would silently discard the
    // binlog retention — a setting disappearing because a different form was
    // submitted.
    fakeBinlog();

    putBinlog(['expire_seconds' => 259200, 'max_binlog_size' => 104857600])->assertOk();

    $files = array_map('basename', File::files($this->dir));

    expect($files)->toBe(['99-serveravatar-binlog.cnf'])
        ->and(File::get($this->dir.'/99-serveravatar-binlog.cnf'))
        ->not->toContain('max_connections');
});

it('reports what the server is holding, not what the directory contains', function () {
    fakeBinlog();

    $read = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    expect($read->json('settings.mysql_binlog.enabled'))->toBeTrue()
        ->and($read->json('settings.mysql_binlog.log_count'))->toBe(2)
        ->and($read->json('settings.mysql_binlog.log_bytes'))->toBe(3145728)
        ->and($read->json('settings.mysql_binlog.oldest_log'))->toBe('mysql-bin.000001')
        ->and($read->json('settings.mysql_binlog.format'))->toBe('ROW');
});

it('reads retention from the older MariaDB variable when that is all there is', function () {
    // A server that answers on expire_logs_days and not the modern name still
    // has a retention policy. Reporting "keeps forever" there would be a wrong
    // answer somebody acts on.
    fakeBinlog(expireDays: '3');

    $read = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    expect($read->json('settings.mysql_binlog.expire_seconds'))->toBe(259200);
});

it('reports binary logging off without inventing logs for it', function () {
    fakeBinlog(logBin: 'OFF');

    $read = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    expect($read->json('settings.mysql_binlog.enabled'))->toBeFalse()
        ->and($read->json('settings.mysql_binlog.log_count'))->toBe(0)
        ->and($read->json('settings.mysql_binlog.log_bytes'))->toBe(0);
});

it('purges through the server rather than the filesystem', function () {
    fakeBinlog();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/settings/mysql-binlog/purge', ['days' => 7])
        ->assertOk();

    // mysqld holds these files open: removing them by hand frees no space and
    // leaves the index naming files that are gone.
    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'PURGE BINARY LOGS BEFORE DATE_SUB(NOW(), INTERVAL 7 DAY)'));
    Process::assertNotRan(fn ($process) => ($process->command[0] ?? '') === 'rm');
});

it('refuses to purge right up to now', function () {
    // Zero days discards the window a point-in-time recovery needs and logs a
    // replica may not have read yet.
    fakeBinlog();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/settings/mysql-binlog/purge', ['days' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('days');
});

it('rejects a log size below what the engine accepts', function () {
    fakeBinlog();

    putBinlog(['expire_seconds' => 604800, 'max_binlog_size' => 1024])
        ->assertUnprocessable()->assertJsonValidationErrors('max_binlog_size');
});

it('allows keep-forever, because a server may already be in that state', function () {
    fakeBinlog();

    putBinlog(['expire_seconds' => 0, 'max_binlog_size' => 104857600])->assertOk();

    expect(File::get($this->dir.'/99-serveravatar-binlog.cnf'))
        ->toContain('binlog_expire_logs_seconds = 0');
});

it('denies a user who cannot manage settings', function () {
    fakeBinlog();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/settings/mysql-binlog', ['expire_seconds' => 60, 'max_binlog_size' => 104857600])
        ->assertForbidden();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/settings/mysql-binlog/purge', ['days' => 7])
        ->assertForbidden();
});

it('denies an unauthenticated request', function () {
    fakeBinlog();

    $this->putJson('/api/settings/mysql-binlog', ['expire_seconds' => 60, 'max_binlog_size' => 104857600])
        ->assertUnauthorized();
    $this->postJson('/api/settings/mysql-binlog/purge', ['days' => 7])->assertUnauthorized();
});
