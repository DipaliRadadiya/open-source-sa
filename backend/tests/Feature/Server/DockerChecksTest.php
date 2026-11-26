<?php

use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Doctor\Checks\DockerCheck;
use App\Services\Server\Doctor\Checks\DockerExposureCheck;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Process\ProcessResult;

/*
 * Docker bypasses the firewall: it inserts rules into the `DOCKER` chain ahead of ufw's, so a port
 * published to 0.0.0.0 is reachable from the internet while the Firewall page shows it closed.
 * Everything the panel generates publishes to 127.0.0.1 behind nginx, so a finding is a container
 * started by hand or a bug in our own template.
 */

/**
 * A ServerOps whose single `run` answers with this stdout/stderr.
 *
 * `$installed` and `$denied` are separate knobs: `sudo -n docker` with no Docker prints "sudo: a
 * password is required" (sudo matches NOPASSWD on a resolved path), so `$denied` is true for an
 * absent Docker and for a stale sudo grant alike.
 */
function dockerOps(string $stdout, string $stderr = '', bool $answered = true, bool $denied = false, bool $installed = true): ServerOps
{
    $process = Mockery::mock(ProcessResult::class);
    $process->shouldReceive('output')->andReturn($stdout);
    $process->shouldReceive('errorOutput')->andReturn($stderr);

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('binaryExists')->andReturn($installed);
    $ops->shouldReceive('run')->andReturn(new ServerOpsResult(
        ok: $answered && $stderr === '',
        reference: 'ref-1',
        result: $process,
        denied: $denied,
        answered: $answered,
    ));

    return $ops;
}

/** The stack the installer recorded, which decides whether absent Docker matters. */
function dockerCapabilities(?string $stack = 'lemp'): ServerCapabilities
{
    $capabilities = Mockery::mock(ServerCapabilities::class);
    $capabilities->shouldReceive('recordedStack')->andReturn($stack);

    return $capabilities;
}

/** DockerCheck over a fake ServerOps, on a box recorded as `$stack`. */
function dockerCheck(ServerOps $ops, ?string $stack = 'lemp'): DockerCheck
{
    return new DockerCheck($ops, dockerCapabilities($stack));
}

it('asks the daemon, not the client binary', function () {
    // `docker --version` reads off the client and succeeds with the daemon stopped; the check
    // must use a command that talks to the daemon.
    $ops = Mockery::mock(ServerOps::class);
    $seen = null;

    $ops->shouldReceive('binaryExists')->andReturn(true);
    $ops->shouldReceive('run')->andReturnUsing(function (array $command) use (&$seen) {
        $seen = $command;

        return new ServerOpsResult(ok: false, reference: 'r', answered: false);
    });

    dockerCheck($ops)->run();

    expect($seen)->toContain('info')
        ->and($seen)->not->toContain('--version');
});

it('passes when the daemon answers with a version', function () {
    $result = dockerCheck(dockerOps("27.3.1\n"))->run();

    expect($result['status'])->toBe('pass')
        ->and($result['detail'])->toContain('27.3.1');
});

it('does not report a failure on a server that simply has no Docker', function () {
    // install.sh ends with `panel:doctor`; this used to print "✗ Docker   docker is installed but
    // the daemon did not answer" on every box that never had Docker. Nothing is wrong, so report
    // neither a failure nor a warning.
    $result = dockerCheck(dockerOps('', 'sudo: a password is required', answered: false, denied: true, installed: false))->run();

    expect($result['status'])->toBe('pass')
        ->and($result['detail'])->toContain('not installed')
        ->and($result['fix'])->toBeNull();
});

it('does warn about absent Docker when the box was built as a Docker stack', function () {
    // Same observation, opposite meaning: Docker is the one component this server exists to run.
    $result = dockerCheck(
        dockerOps('', 'sudo: a password is required', answered: false, denied: true, installed: false),
        stack: 'docker',
    )->run();

    expect($result['status'])->toBe('warn')
        ->and($result['fix'])->toBe('doctor.fixes.docker_missing');
});

it('names a sudo refusal as a sudo refusal, not a dead daemon', function () {
    // Docker is installed and sudo refused anyway: the grant is stale (`panel:sudoers` rewrites
    // it). Read `$denied`, not sudo's localised, version-dependent wording.
    $result = dockerCheck(dockerOps('', 'sudo: a password is required', answered: false, denied: true))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.docker_sudo')
        ->and($result['detail'])->toContain('sudo grant');
});

it('keeps the socket permission refusal separate from the sudo one', function () {
    // The daemon is reachable and refused the connection: different advice, and never "join the
    // docker group", which is root on this box.
    $result = dockerCheck(dockerOps('', 'permission denied while trying to connect'))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.docker_denied');
});

it('still fails when Docker is installed and its daemon is down', function () {
    // The case the old code reported for everything; still a real failure.
    $result = dockerCheck(dockerOps('', 'Cannot connect to the Docker daemon'))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.docker_down');
});

it('asks whether docker exists before it asks the daemon anything', function () {
    // Ordering is the fix: with no binary present the elevated call must not be made at all.
    // Mockery fails the test if `run` is called.
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('binaryExists')->with('docker')->once()->andReturn(false);
    $ops->shouldReceive('run')->never();

    expect(dockerCheck($ops)->run()['status'])->toBe('pass');
});

it('finds a container published to every address', function () {
    $ops = dockerOps("web\t0.0.0.0:8080->80/tcp, :::8080->80/tcp\n");
    $result = (new DockerExposureCheck($ops))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['detail'])->toContain('web')
        ->and($result['fix'])->toBe('doctor.fixes.docker_exposure');
});

it('accepts a loopback publish, which is what the panel generates', function () {
    $result = (new DockerExposureCheck(dockerOps("web\t127.0.0.1:8080->80/tcp\n")))->run();

    expect($result['status'])->toBe('pass');
});

it('reads the host side of the mapping, not the container side', function () {
    // A parser that searches the whole string for "0.0.0.0" would flag a correctly bound
    // container, and a check that cries wolf gets switched off.
    // An exposed-but-unpublished port has no arrow and is reachable only from other containers.
    $ops = dockerOps("web\t127.0.0.1:8080->80/tcp\napi\t8080/tcp\n");

    expect((new DockerExposureCheck($ops))->run()['status'])->toBe('pass');
});

it('catches the IPv6 wildcard', function () {
    // `[::]:8080->80/tcp` is every address over v6.
    $result = (new DockerExposureCheck(dockerOps("web\t[::]:8080->80/tcp\n")))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['detail'])->toContain('web');
});

it('does not cry wolf over an IPv6 loopback bind', function () {
    // `[::1]:8080` is v6 loopback and must pass. Split on the first colon the address reads as `[`,
    // trims to empty and counts as a wildcard, so a safe binding is reported as exposed.
    // The wildcard case above does not discriminate: it passes against the broken parser too.
    $result = (new DockerExposureCheck(dockerOps("web\t[::1]:8080->80/tcp\n")))->run();

    expect($result['status'])->toBe('pass');
});

it('stays quiet when Docker is not there, so one cause is not two failures', function () {
    // Docker being absent is DockerCheck's business; two failures for one cause bury the cause.
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('binaryExists')->andReturn(false);
    $ops->shouldReceive('run')->never();

    expect((new DockerExposureCheck($ops))->run()['status'])->toBe('pass');
});

it('says so when it was not allowed to look, rather than reporting nothing exposed', function () {
    // Here "no finding" is a security claim: a denied sudo used to read "nothing to expose",
    // green, on a box with containers it was never permitted to list.
    // Docker present + sudo refused is unproven, not proven safe.
    $result = (new DockerExposureCheck(
        dockerOps('', 'sudo: a password is required', answered: false, denied: true)
    ))->run();

    expect($result['status'])->toBe('warn')
        ->and($result['detail'])->toContain('not checked')
        ->and($result['fix'])->toBe('doctor.fixes.docker_sudo');
});

it('translates both checks and every fix in every locale', function () {
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        foreach (['docker', 'docker_exposure'] as $key) {
            expect(__("doctor.checks.{$key}", [], $locale))
                ->not->toBe("doctor.checks.{$key}", "check name {$key} missing in {$locale}");
        }

        foreach (['docker_missing', 'docker_down', 'docker_denied', 'docker_sudo', 'docker_exposure'] as $key) {
            expect(__("doctor.fixes.{$key}", [], $locale))
                ->not->toBe("doctor.fixes.{$key}", "fix {$key} missing in {$locale}");
        }
    }
});

it('grants docker to the panel rather than the site user to the docker group', function () {
    // Group membership is root equivalence (a member can bind-mount / into a container), so the
    // panel elevates the command and nobody else gets the socket.
    expect(config('server.privilege.binaries'))->toContain('docker');
});
