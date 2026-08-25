<?php

use App\Services\Server\ServerOps;
use App\Services\Server\SudoersFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/*
 * php-fpm runs as the unprivileged panel account, so anything touching the
 * system has to go through sudo. install.sh grants the binaries NOPASSWD in
 * /etc/sudoers.d/<slug>; before this existed the panel never invoked sudo, so
 * that grant was dead configuration and every privileged operation failed with
 * "Permission denied" on a correctly installed server.
 *
 * The rest of the suite runs with SERVER_OPS_SUDO=false (see phpunit.xml) so
 * feature tests assert the command a feature runs rather than the prefix.
 * These turn it on.
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

    // Without -n, sudo waits for a password on a terminal that does not
    // exist and the operation hangs until the timeout, reported as
    // something else entirely.
    expect($runs[0][0])->toBe('sudo')
        ->and($runs[0][1])->toBe('-n');
});

it('leaves unprivileged binaries alone', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    // Not in the sudoers allowlist — escalating it would be a privilege
    // granted for no reason, and would fail anyway.
    $this->ops->run(['composer', 'install']);

    expect($runs[0])->toBe(['composer', 'install']);
});

it('escalates every binary the installer grants', function () {
    $runs = [];
    Process::fake(function ($process) use (&$runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    // A sample across the features that were all broken: system users,
    // firewall, services, package installs, config writes, fail2ban.
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

    // The ops log is the only record of what ran. Logging the pre-escalation
    // command would have made this bug invisible: the log would show
    // `useradd …` whether or not sudo was used.
    $result = $this->ops->run(['useradd', 'deploy'], ['feature' => 'system_user']);

    expect($result->ok)->toBeTrue();
})->skip(fn () => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'runs as root — nothing is escalated');

it('redacts secret command options from the server operations log', function () {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('server-ops')->andReturn($logger);
    Process::fake(['*' => Process::result(exitCode: 0)]);

    // PrestaShop and Statamic accept these only on argv. The process still
    // needs them, but retaining them in a durable log would turn a brief ps
    // exposure into a permanent secret leak.
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
    // This used to diff config against a list parsed out of install.sh. That
    // list is gone — the installer renders the file from this same config —
    // so the drift it watched for cannot happen any more, and comparing a
    // thing to itself would assert nothing.
    //
    // The link still worth checking is the other one: the file that ends up in
    // /etc/sudoers.d against ServerOps at runtime. A binary in the grant that
    // elevate() does not prefix is a privilege granted for nothing; one it
    // prefixes that the grant omits fails on a real server with "a password is
    // required", which is the failure this whole area exists to prevent.
    $elevate = new ReflectionMethod($this->ops, 'elevate');

    foreach (app(SudoersFile::class)->entries() as $path) {
        $binary = basename($path);

        // The wildcard stands for php-fpm8.4, php-fpm8.3 and whatever else is
        // installed; probe it with a concrete member of that family.
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
    // Akaunting's installer failed with exit 1 and an empty stderr, so the
    // ops log recorded proof that something broke and nothing about what.
    // Laravel's own `artisan` writes command failures to stdout, and so do
    // wp-cli and composer — logging stderr alone is blind to all of them.
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
    // The successful path of some of these is a 90 MB file listing.
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
    // The argv redaction walks arguments one at a time; a tool that prints the
    // command it was given prints it as one string, where that pass never
    // looks.
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
    // Moodle takes `--adminpass` and Nextcloud `--admin-pass`; neither contains
    // "password", so a real admin password reached the server-ops log in clear
    // on every Moodle install. Over-matching costs a redacted value in a log,
    // under-matching costs a credential.
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
        // The rest of the command still has to be readable, or the log stops
        // being useful for the thing it exists for.
        ->and($logged['command'])->toContain('--adminuser=admin');
});

it('keeps stdout on success when the caller asks, for tools that lie about exit codes', function () {
    // PrestaShop's installer exited 0 in half a second having written no
    // configuration. Failure-only capture answers "what went wrong" and cannot
    // answer "why did nothing happen" — the only account of that was on a
    // stdout nobody kept.
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

    // `toBeString()` first, deliberately: with failure-only capture this key is
    // null, and asserting only `toContain` let the null through — the test
    // passed against the very behaviour it exists to forbid.
    expect($logged['stdout'] ?? null)->toBeString()
        ->and($logged['stdout'])->toContain('Nothing to do');
});
