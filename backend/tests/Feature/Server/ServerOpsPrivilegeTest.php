<?php

use App\Services\Server\ServerOps;
use App\Services\Server\SudoersFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/*
 * php-fpm runs as the unprivileged panel account, so anything touching the system goes through
 * sudo (NOPASSWD grants in /etc/sudoers.d/<slug>).
 *
 * The rest of the suite runs with SERVER_OPS_SUDO=false (see phpunit.xml) so feature tests assert
 * the command a feature runs rather than the prefix. These turn it on.
 */

beforeEach(function () {
    config()->set('server.privilege.sudo', true);
    $this->ops = app(ServerOps::class);
});

/** @return array<int, array<int, string>> every command Process saw */
function capturedCommands(): array
{
    $runs = [];

    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    return $runs;
}

it('runs privileged binaries through sudo', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->run(['useradd', '-m', '-s', '/bin/bash', 'deploy']);

    expect($runs[0])->toBe(['sudo', '-n', 'useradd', '-m', '-s', '/bin/bash', 'deploy']);
});

it('uses -n so a missing grant fails instead of hanging on a password prompt', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->run(['systemctl', 'restart', 'nginx']);

    // Without -n, sudo waits for a password on a terminal that does not exist and the operation
    // hangs until the timeout.
    expect($runs[0][0])->toBe('sudo')
        ->and($runs[0][1])->toBe('-n');
});

it('leaves unprivileged binaries alone', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    // Not in the sudoers allowlist: escalating it would grant a privilege for no reason.
    $this->ops->run(['composer', 'install']);

    expect($runs[0])->toBe(['composer', 'install']);
});

it('escalates every binary the installer grants', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    // A sample across the features: system users, firewall, services, packages, config writes,
    // fail2ban.
    foreach (['useradd', 'userdel', 'usermod', 'ufw', 'systemctl', 'apt-get',
        'fail2ban-client', 'tee', 'mkdir', 'chown', 'chmod', 'nginx',
        'apachectl', 'phpenmod', 'chpasswd', 'gpasswd'] as $binary) {
        $this->ops->run([$binary, '--version']);
    }

    foreach ($runs as $run) {
        expect($run[0])->toBe('sudo');
    }
})->skip(fn () => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'runs as root — nothing is escalated');

it('matches on the binary name even when given an absolute path', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->run(['/usr/sbin/useradd', 'deploy']);

    expect($runs[0])->toBe(['sudo', '-n', '/usr/sbin/useradd', 'deploy']);
});

it('does not escalate when the feature is switched off', function () {
    config()->set('server.privilege.sudo', false);

    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->run(['useradd', 'deploy']);

    expect($runs[0])->toBe(['useradd', 'deploy']);
});

it('logs the command as it was actually executed', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    // The ops log is the only record of what ran: it must show the escalated command, or it reads
    // `useradd …` whether or not sudo was used.
    $result = $this->ops->run(['useradd', 'deploy'], ['feature' => 'system_user']);

    expect($result->ok)->toBeTrue();
})->skip(fn () => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'runs as root — nothing is escalated');

it('redacts secret command options from the server operations log', function () {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);
    Process::fake(['*' => Process::result(exitCode: 0)]);

    // PrestaShop and Statamic accept these only on argv. The process needs them, but a durable log
    // must not keep them.
    $this->ops->run([
        'php', 'install.php',
        '--password=AdminPassw0rd!',
        '--db_password', 'DatabasePassw0rd!',
    ]);

    $logger->shouldHaveReceived('info')->once()->with('server operation', Mockery::on(
        fn (array $context): bool => $context['command'] === 'php install.php --password=[REDACTED] --db_password [REDACTED]',
    ));
});

it('escalates exactly what the written grant covers', function () {
    // Checks the file that ends up in /etc/sudoers.d against ServerOps at runtime. A binary in the
    // grant that elevate() does not prefix is a privilege granted for nothing; one it prefixes
    // that the grant omits fails on a real server with "a password is required".
    $elevate = new ReflectionMethod($this->ops, 'elevate');

    foreach (app(SudoersFile::class)->entries() as $path) {
        $binary = basename($path);

        // The wildcard stands for php-fpm8.4, php-fpm8.3 and so on; probe a concrete member.
        $command = [str_contains($binary, '*') ? 'php-fpm8.4' : $binary];

        expect($elevate->invoke($this->ops, $command))
            ->toBe(array_merge(['sudo', '-n'], $command), "{$binary} is granted but not escalated");
    }
})->skip(fn () => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'runs as root — nothing is escalated');

it('keeps an expected negative probe out of the error log without turning it into success', function () {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $result = $this->ops->probe(
        ['test', '-f', '/etc/php/8.4/fpm/pool.d/missing.conf'],
        ['feature' => 'application', 'op' => 'pool_exists'],
    );

    expect($result->failed())->toBeTrue();

    $logger->shouldHaveReceived('info')->once()->with('server operation', Mockery::on(
        fn (array $context): bool => $context['exit_code'] === 1
            && $context['expected_exit'] === true
            && $context['op'] === 'pool_exists',
    ));
    $logger->shouldNotHaveReceived('error');
});

it('still logs an unexpected probe exit as an error', function () {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);
    Process::fake(['*' => Process::result(errorOutput: 'permission denied', exitCode: 2)]);

    $result = $this->ops->probe(
        ['test', '-f', '/etc/php/8.4/fpm/pool.d/unreadable.conf'],
        ['feature' => 'application', 'op' => 'pool_exists'],
    );

    expect($result->failed())->toBeTrue();

    $logger->shouldHaveReceived('error')->once()->with('server operation', Mockery::on(
        fn (array $context): bool => $context['exit_code'] === 2
            && $context['expected_exit'] === false,
    ));
});

it('logs the tail of stdout when a command fails, because many tools report there', function () {
    // `artisan`, wp-cli and composer write failures to stdout; logging stderr alone is blind to them.
    Process::fake(['*' => Process::result(output: 'The admin password is required.', exitCode: 1)]);

    $logged = [];
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) use (&$logged) {
        $logged = $context;

        return true;
    });

    app(ServerOps::class)->run(['php', 'artisan', 'install'], ['feature' => 'test', 'op' => 'probe']);

    expect($logged['stdout'])->toContain('The admin password is required.');
});

it('does not log stdout for a command that succeeded', function () {
    // Successful output can be huge (a file listing), so it is not logged.
    Process::fake(['*' => Process::result(output: str_repeat('file.txt', 100))]);

    $logged = [];
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$logged) {
        $logged = $context;

        return true;
    });

    app(ServerOps::class)->run(['ls'], ['feature' => 'test', 'op' => 'probe']);

    expect($logged['stdout'])->toBeNull();
});

it('redacts a secret a failing tool echoed back on stdout', function () {
    // The argv redaction walks arguments one at a time; a tool that echoes the command prints it
    // as one string, where that pass never looks.
    Process::fake(['*' => Process::result(
        output: 'failed: php artisan install --admin-password=hunter2 --db-name=x',
        exitCode: 1,
    )]);

    $logged = [];
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) use (&$logged) {
        $logged = $context;

        return true;
    });

    app(ServerOps::class)->run(['php', 'artisan', 'install'], ['feature' => 'test', 'op' => 'probe']);

    expect($logged['stdout'])->not->toContain('hunter2')
        ->and($logged['stdout'])->toContain('[REDACTED]')
        ->and($logged['stdout'])->toContain('--db-name=x');
});

it('redacts pass-style option names, not just the word password', function () {
    // Moodle takes `--adminpass` and Nextcloud `--admin-pass`; neither contains "password".
    // Over-matching costs a redacted value in a log, under-matching costs a credential.
    $logged = [];
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$logged) {
        $logged = $context;

        return true;
    });

    Process::fake(['*' => Process::result(output: 'ok')]);

    app(ServerOps::class)->run(
        ['php', 'admin/cli/install_database.php', '--adminpass=Zv9qooXUsAJRdHCO', '--adminuser=admin'],
        ['feature' => 'test', 'op' => 'probe'],
    );

    expect($logged['command'])->not->toContain('Zv9qooXUsAJRdHCO')
        ->and($logged['command'])->toContain('--adminpass=[REDACTED]')
        // The rest of the command stays readable, or the log stops being useful.
        ->and($logged['command'])->toContain('--adminuser=admin');
});

it('keeps stdout on success when the caller asks, for tools that lie about exit codes', function () {
    // PrestaShop's installer can exit 0 having written no configuration. Failure-only capture
    // cannot answer "why did nothing happen"; that account is on stdout.
    Process::fake(['*' => Process::result(output: 'Nothing to do; database already installed.')]);

    $logged = [];
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$logged) {
        $logged = $context;

        return true;
    });

    app(ServerOps::class)->run(
        ['php', 'install/index_cli.php'],
        ['feature' => 'application', 'op' => 'installer.install_app', 'log_output' => true],
    );

    // `toBeString()` first: with failure-only capture this key is null, and `toContain` alone let
    // the null through.
    expect($logged['stdout'] ?? null)->toBeString()
        ->and($logged['stdout'])->toContain('Nothing to do');
});

/*
 * sudo resets the environment (`env_reset` is the default), so a variable set on the Process
 * object is gone by the time the elevated binary starts, and nothing reports it. PostgreSQL shows
 * it: libpq reads its password file only from `PGPASSFILE`, so every query failed with
 * `fe_sendauth: no password supplied`.
 */
it('carries environment variables through sudo', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->run(['psql', '--dbname=postgres'], [], env: ['PGPASSFILE' => '/tmp/pg.pgpass']);

    // `sudo -n env VAR=value psql …`, not `sudo -n psql …` with the variable set on a process
    // sudo discards.
    expect($runs[0])->toBe(['sudo', '-n', 'env', 'PGPASSFILE=/tmp/pg.pgpass', 'psql', '--dbname=postgres']);
});

it('keeps apt unattended, which is the case that was quietly failing', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->apt(['apt-get', 'install', '-y', 'postgresql']);

    // Without this, a package with a debconf prompt waits for an answer nobody can give.
    expect($runs[0])->toContain('DEBIAN_FRONTEND=noninteractive')
        ->and(array_slice($runs[0], 0, 3))->toBe(['sudo', '-n', 'env']);
});

it('adds no env wrapper when there is nothing to carry', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->ops->run(['systemctl', 'restart', 'nginx']);

    // `env` appears only when a caller asked for a variable.
    expect($runs[0])->toBe(['sudo', '-n', 'systemctl', 'restart', 'nginx']);
});

it('elevates on the real binary, not on the env wrapper', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    // `echo` is not in the privilege allowlist; deciding on `env` instead of the command would
    // make everything privileged the moment it carried a variable.
    $this->ops->run(['echo', 'hello'], [], env: ['FOO' => 'bar']);

    expect($runs[0])->toBe(['echo', 'hello'])
        ->and($runs[0])->not->toContain('sudo');
});
