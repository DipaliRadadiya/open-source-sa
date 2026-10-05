<?php

use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Doctor\Checks\DockerCheck;
use App\Services\Server\Doctor\Checks\DockerExposureCheck;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Process\ProcessResult;

/*
 * Docker is a different risk class from nginx and PHP, and the reason is one
 * sentence: **it bypasses the firewall.**
 *
 * Docker inserts rules into the `DOCKER` chain ahead of the filter rules ufw
 * manages, so a port published to 0.0.0.0 is reachable from the internet while
 * the panel's Firewall page shows it closed. The page is not wrong about ufw;
 * ufw is simply not what decides. Nothing else in the panel can see this, so
 * the exposure check ships before the panel can create a container at all.
 *
 * Everything the panel generates publishes to 127.0.0.1 behind nginx. A
 * finding is therefore either a container someone started by hand or a bug in
 * our own template, and both need saying out loud.
 */

/**
 * A ServerOps whose single `run` answers with this stdout/stderr.
 *
 * `$installed` and `$denied` are separate knobs on purpose, because the bug
 * these checks shipped was the two being the same observation. `sudo -n docker`
 * on a box with no Docker prints "sudo: a password is required" — sudo matches
 * its NOPASSWD rules on a resolved absolute path, and an absent binary resolves
 * to nothing — so `$denied` is true for an absent Docker *and* for a stale sudo
 * grant. A fake that could not express "absent" separately from "refused" would
 * reproduce the bug rather than catch it.
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
    // `docker --version` reads straight off the client and succeeds with the
    // daemon stopped — it answers "is the package installed" while appearing
    // to answer "does Docker work". The check must use a command that talks to
    // the daemon.
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
    // The bug, stated as a test. install.sh runs `panel:doctor` as its last
    // step, so this was the final line of every lemp, lamp, mern and ols
    // install:
    //
    //     ✗ Docker   docker is installed but the daemon did not answer
    //
    // on boxes that had never had Docker on them. Nothing is wrong, so nothing
    // is reported: not a failure and not a warning either. A yellow line on
    // four stacks out of five is how an operator learns to stop reading this.
    $result = dockerCheck(dockerOps('', 'sudo: a password is required', answered: false, denied: true, installed: false))->run();

    expect($result['status'])->toBe('pass')
        ->and($result['detail'])->toContain('not installed')
        ->and($result['fix'])->toBeNull();
});

it('does warn about absent Docker when the box was built as a Docker stack', function () {
    // Same observation, opposite meaning: here Docker is the one component the
    // server exists to run, and without it no container site can start.
    $result = dockerCheck(
        dockerOps('', 'sudo: a password is required', answered: false, denied: true, installed: false),
        stack: 'docker',
    )->run();

    expect($result['status'])->toBe('warn')
        ->and($result['fix'])->toBe('doctor.fixes.docker_missing');
});

it('names a sudo refusal as a sudo refusal, not a dead daemon', function () {
    // Docker IS installed and sudo refused anyway — the grant is stale, which
    // is what `panel:sudoers` rewrites. Read off `$denied` rather than the
    // prose: sudo's wording is localised and version-dependent, and matching
    // it as a string is what made this indistinguishable from the case above.
    $result = dockerCheck(dockerOps('', 'sudo: a password is required', answered: false, denied: true))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.docker_sudo')
        ->and($result['detail'])->toContain('sudo grant');
});

it('keeps the socket permission refusal separate from the sudo one', function () {
    // The daemon is up and reachable and refused the panel's connection. A
    // different fault with different advice — and the advice must not be "join
    // the docker group", which is root on this box.
    $result = dockerCheck(dockerOps('', 'permission denied while trying to connect'))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.docker_denied');
});

it('still fails when Docker is installed and its daemon is down', function () {
    // The case the old code reported for everything. It has to keep working:
    // nothing containerised can run, and that is a real failure.
    $result = dockerCheck(dockerOps('', 'Cannot connect to the Docker daemon'))->run();

    expect($result['status'])->toBe('fail')
        ->and($result['fix'])->toBe('doctor.fixes.docker_down');
});

it('asks whether docker exists before it asks the daemon anything', function () {
    // Ordering is the whole fix, so it is asserted directly: with no binary
    // present the elevated call must not be made at all. Mockery fails the test
    // if `run` is called, which is the point.
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('binaryExists')->with('docker')->once()->andReturn(false);
    $ops->shouldReceive('run')->never();

    expect(dockerCheck($ops)->run()['status'])->toBe('pass');
});

it('finds a container published to every address', function () {
    // The finding this whole check exists for.
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
    // `127.0.0.1:8080->0.0.0.0/tcp` is nonsense, but a parser that searches the
    // whole string for "0.0.0.0" would flag a correctly bound container — and
    // a check that cries wolf on the safe case gets switched off.
    //
    // Conversely an exposed-but-unpublished port has no arrow at all and is
    // reachable only from other containers: not a finding.
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
    // The case that actually discriminates a correct parser from a naive one,
    // and I got it wrong first: I asserted the v6 *wildcard* above would catch
    // a first-colon split, and it does — but only by accident, because the
    // mangled address comes out empty and empty is already treated as a
    // wildcard. The test passed against the broken parser.
    //
    // `[::1]:8080` is v6 loopback and must PASS. Split on the first colon and
    // the address reads as `[`, which trims to empty, which is a wildcard —
    // so a safe binding is reported as exposed. A security check that cries
    // wolf on the safe case is one people switch off.
    $result = (new DockerExposureCheck(dockerOps("web\t[::1]:8080->80/tcp\n")))->run();

    expect($result['status'])->toBe('pass');
});

it('stays quiet when Docker is not there, so one cause is not two failures', function () {
    // Docker being absent is DockerCheck's business. Both checks failing for
    // one reason buries the reason.
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('binaryExists')->andReturn(false);
    $ops->shouldReceive('run')->never();

    expect((new DockerExposureCheck($ops))->run()['status'])->toBe('pass');
});

it('says so when it was not allowed to look, rather than reporting nothing exposed', function () {
    // The exposure check is the one place where "no finding" is a security
    // claim, and a denied sudo used to produce exactly that claim: "no
    // reachable Docker daemon — nothing to expose", green, on a box with
    // running containers it had never been permitted to list.
    //
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
    // The security model in one assertion. Group membership is root
    // equivalence — a member can bind mount / into a container and write
    // anywhere — so the panel elevates the command and nobody else gets the
    // socket.
    expect(config('server.privilege.binaries'))->toContain('docker');
});
