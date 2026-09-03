<?php

use App\Models\User;
use App\Services\Server\Databases\SqlEngineLocator;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
 * `max_connections`, applied to the running server and persisted to a drop-in.
 *
 * The case worth having tests for is not the happy path. MySQL and MariaDB
 * silently reduce `max_connections` when `open_files_limit` cannot support it,
 * with no error anywhere — so the interesting question is what the panel says
 * when the server does not do what it was told. Answering "saved: 2000" while
 * the server runs 214 is the same failure that put 33 million queries a second
 * on the database chart: the panel asserting something the server contradicts.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->dir = sys_get_temp_dir().'/sv-oss-mysql-'.uniqid();
    File::ensureDirectoryExists($this->dir);

    config([
        'server.databases.engines.mariadb.config_dir' => $this->dir,
        'server.databases.auth_file_dir' => sys_get_temp_dir(),
    ]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/**
 * Fake the SQL client, branching on the statement piped to stdin.
 *
 * `$effective` is what the server reports after the SET — pass something lower
 * than the request to stand in for the open_files_limit cap.
 */
function fakeMysql(int $effective = 200, int $openFiles = 65535, bool $reachable = true): void
{
    Process::fake(function ($process) use ($effective, $openFiles, $reachable) {
        $cmd = $process->command;

        // ServerOps prefixes privileged operations with sudo here.
        if (($cmd[0] ?? null) === 'sudo' && ($cmd[1] ?? null) === '-n') {
            $cmd = array_slice($cmd, 2);
        }

        // Config writes and reads go through ServerOps rather than File::put,
        // so the fake has to perform them — otherwise the drop-in never
        // appears and the assertions below check nothing at all.
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

        if (! $reachable) {
            return Process::result(exitCode: 1, errorOutput: 'connect refused');
        }

        return match (true) {
            str_contains($sql, "LIKE 'max_connections'") => Process::result(output: "max_connections\t{$effective}\n"),
            str_contains($sql, "LIKE 'open_files_limit'") => Process::result(output: "open_files_limit\t{$openFiles}\n"),
            str_contains($sql, 'SET GLOBAL max_connections') => Process::result(output: ''),
            str_contains($sql, 'SHOW GLOBAL STATUS') => Process::result(output: "Threads_connected\t7\nQueries\t100\nSlow_queries\t0\nUptime\t3600\n"),
            default => Process::result(output: '1'),
        };
    });
}

function putMaxConnections(int $value): TestResponse
{
    return test()
        ->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/mysql', ['max_connections' => $value]);
}

it('writes the value to a drop-in so it survives a restart', function () {
    fakeMysql(effective: 500);

    putMaxConnections(500)->assertOk();

    $dropIn = $this->dir.'/99-serveravatar.cnf';

    expect(File::exists($dropIn))->toBeTrue()
        ->and(File::get($dropIn))->toContain('[mysqld]')
        ->and(File::get($dropIn))->toContain('max_connections = 500');
});

it('applies the value to the running server, so no restart is needed', function () {
    fakeMysql(effective: 500);

    putMaxConnections(500)->assertOk();

    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'SET GLOBAL max_connections = 500'));
});

it('reports the value the server adopted, not the one that was asked for', function () {
    // The whole point: 2000 requested, 214 granted because open_files_limit
    // cannot support more. The response must carry 214.
    fakeMysql(effective: 214, openFiles: 1024);

    $response = putMaxConnections(2000)->assertOk();

    expect($response->json('mysql.max_connections'))->toBe(214)
        ->and($response->json('mysql.configured_max_connections'))->toBe(2000)
        ->and($response->json('mysql.capped'))->toBeTrue()
        ->and($response->json('mysql.open_files_limit'))->toBe(1024);
});

it('does not claim a cap when the server did what it was told', function () {
    fakeMysql(effective: 500);

    expect(putMaxConnections(500)->json('mysql.capped'))->toBeFalse();
});

it('never touches my.cnf', function () {
    fakeMysql();

    putMaxConnections(300)->assertOk();

    // Only our own drop-in exists in the managed directory.
    expect(array_map('basename', File::files($this->dir)))->toBe(['99-serveravatar.cnf']);
});

it('refuses a value the panel could not reach the database through', function () {
    fakeMysql();

    putMaxConnections(1)->assertUnprocessable()->assertJsonValidationErrors('max_connections');
    putMaxConnections(0)->assertUnprocessable()->assertJsonValidationErrors('max_connections');
    putMaxConnections(-50)->assertUnprocessable()->assertJsonValidationErrors('max_connections');

    expect(File::exists($this->dir.'/99-serveravatar.cnf'))->toBeFalse();
});

it('rejects a value past the point where the number stops meaning anything', function () {
    fakeMysql();

    putMaxConnections(500000)->assertUnprocessable()->assertJsonValidationErrors('max_connections');
});

it('reports sizing guidance without enforcing it', function () {
    // Advice, not a limit: a request above the recommendation still succeeds,
    // because the operator knows their workload and the panel does not.
    fakeMysql(effective: 4000);

    $response = putMaxConnections(4000)->assertOk();

    expect($response->json('mysql.recommended_max'))->toBeInt()
        ->and($response->json('mysql.recommended_max'))->toBeLessThan(4000)
        ->and($response->json('mysql.max_connections'))->toBe(4000);
});

it('denies a user who cannot manage settings', function () {
    fakeMysql();

    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting', view: true, manage: false);

    $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->putJson('/api/settings/mysql', ['max_connections' => 300])
        ->assertForbidden();

    expect(File::exists($this->dir.'/99-serveravatar.cnf'))->toBeFalse();
});

it('denies an unauthenticated request', function () {
    fakeMysql();

    $this->putJson('/api/settings/mysql', ['max_connections' => 300])->assertUnauthorized();
});

it('still offers the group when the engine is installed but not answering', function () {
    // This assertion used to be the opposite — it expected the group to vanish
    // when `SELECT 1` failed, which is precisely the bug: a box with MariaDB
    // installed and running lost the card and was told nothing was installed.
    // Absence is now decided by the unit and the config directory, neither of
    // which can be wrong for the reason a rejected password can.
    fakeMysql(reachable: false);

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    expect($settings->json('settings.mysql'))->not->toBeNull()
        ->and($settings->json('settings.mysql.reachable'))->toBeFalse();
});

/*
 * The three states, and why they are three.
 *
 * A running MariaDB whose stored credentials are rejected used to be reported
 * as "no MySQL or MariaDB server is running on this machine" — the panel
 * asserting something the server flatly contradicted. Reproduced on a real box
 * where MariaDB was up, the client installed, and the admin connection still
 * held the firstOrCreate default of root with no password.
 */

function fakeUnreachable(): void
{
    Process::fake(function ($process) {
        $cmd = $process->command;

        if (($cmd[0] ?? null) === 'sudo' && ($cmd[1] ?? null) === '-n') {
            $cmd = array_slice($cmd, 2);
        }

        // The unit exists — the engine is installed and systemd knows it.
        if (($cmd[0] ?? '') === 'systemctl') {
            return Process::result(output: '# /lib/systemd/system/mariadb.service');
        }

        if (($cmd[0] ?? '') === 'cat') {
            return Process::result(exitCode: 1, errorOutput: 'No such file');
        }

        // ...but every statement is refused.
        return Process::result(exitCode: 1, errorOutput: "ERROR 1698 (28000): Access denied for user 'root'@'localhost'");
    });
}

it('does not call a running engine absent just because it cannot log in', function () {
    fakeUnreachable();

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    // The group is still offered: a card that disappears cannot explain itself.
    expect($settings->json('settings.mysql'))->not->toBeNull()
        ->and($settings->json('settings.mysql.present'))->toBeTrue()
        ->and($settings->json('settings.mysql.reachable'))->toBeFalse();
});

it('reports no reading rather than a zero when the engine cannot be reached', function () {
    fakeUnreachable();

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    // A zero here would read as "a database allowing no connections", which is
    // a measurement nobody took.
    expect($settings->json('settings.mysql.max_connections'))->toBeNull();
});

it('refuses to save against an unreachable engine rather than half-applying', function () {
    fakeUnreachable();

    // Writing the drop-in and failing the SET GLOBAL would leave the panel
    // claiming a value that only arrives at the next restart.
    putMaxConnections(300)->assertUnprocessable()->assertJsonValidationErrors('engine');

    expect(File::exists($this->dir.'/99-serveravatar.cnf'))->toBeFalse();
});

it('says the engine is absent only when nothing is installed', function () {
    Process::fake(function ($process) {
        $cmd = $process->command;

        if (($cmd[0] ?? null) === 'sudo' && ($cmd[1] ?? null) === '-n') {
            $cmd = array_slice($cmd, 2);
        }

        // No unit, no config directory, nothing answering.
        return Process::result(exitCode: 1, errorOutput: 'not found');
    });

    // Nothing on disk at all: no config directory and no unit file. This box
    // genuinely has MariaDB installed, so the unit directories have to be
    // pointed somewhere empty or the check correctly finds the real one.
    config([
        'server.databases.engines.mariadb.config_dir' => '/nonexistent-'.uniqid(),
        'server.databases.engines.mysql.config_dir' => '/nonexistent-'.uniqid(),
        'server.systemd_unit_dirs' => ['/nonexistent-'.uniqid()],
    ]);

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    expect($settings->json('settings.mysql'))->toBeNull();
});

/*
 * Presence must not depend on a privileged command.
 *
 * Reported from a real box running the panel as `www-data`: the config
 * directory was readable and the answer was sitting right there, but the
 * systemd probe ran first, `sudo -n systemctl cat` was denied, ServerOps
 * logged the denial, the log file was not writable by that user, and the
 * logging exception propagated out of a method that answers true or false.
 * A probe that could not answer the question stopped the one that could.
 */

it('detects the engine without running a single privileged command', function () {
    // Every subprocess fails, exactly as a denied sudo would.
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'sudo: a password is required'));

    // ...but the config directory is there, which is all presence needs.
    expect(app(SqlEngineLocator::class)->present())
        ->toBe('mariadb');

    Process::assertNotRan(fn ($process) => in_array('systemctl', $process->command, true));
});

it('offers the group to a panel user who cannot sudo', function () {
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'sudo: a password is required'));

    $settings = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();

    expect($settings->json('settings.mysql'))->not->toBeNull()
        ->and($settings->json('settings.mysql.present'))->toBeTrue();
});

it('survives a probe that throws rather than taking the page down with it', function () {
    // Logging a failed operation can itself fail — an unwritable log file is
    // enough. The settings page must still render: the other groups have
    // nothing to do with the database.
    Process::fake(function () {
        throw new RuntimeException('The stream or file could not be opened in append mode');
    });

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/settings')->assertOk();
});
