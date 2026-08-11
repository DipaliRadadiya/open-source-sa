<?php

use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Process;

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

it('keeps the allowlist in step with what install.sh grants', function () {
    // Drift here is silent and only shows up on a real server: a binary in
    // config but not sudoers fails at runtime; one in sudoers but not config
    // is a privilege granted for nothing.
    $installer = file_get_contents(dirname(base_path()).'/install.sh');
    expect($installer)->not->toBeFalse();

    // Terminated on a `)` at the start of its own line, not the first `)` in
    // the block: the list is commented, and a comment mentioning `asUser()`
    // ended the match early — so this compared config against roughly half
    // the grants and reported three dozen phantom omissions.
    preg_match('/local bins=\((.*?)\n\s*\)/s', $installer, $matches);
    expect($matches[1] ?? null)->not->toBeNull();

    $granted = collect(explode("\n", $matches[1]))
        // Drop comment lines before splitting on whitespace, or every word in
        // the prose becomes a "granted binary".
        ->reject(fn (string $line): bool => str_starts_with(trim($line), '#'))
        ->flatMap(fn (string $line): array => preg_split('/\s+/', trim($line)) ?: [])
        ->filter()
        // `php-fpm*` is a wildcard covering one binary per installed PHP
        // version; ServerOps matches it by prefix rather than by name.
        ->reject(fn (string $path): bool => str_contains($path, '*'))
        ->map(fn (string $path): string => basename($path))
        ->unique()
        ->sort()
        ->values();

    $configured = collect(config('server.privilege.binaries'))->unique()->sort()->values();

    expect($configured->diff($granted)->all())->toBe([], 'in config but not granted by install.sh')
        ->and($granted->diff($configured)->all())->toBe([], 'granted by install.sh but not in config');
});
