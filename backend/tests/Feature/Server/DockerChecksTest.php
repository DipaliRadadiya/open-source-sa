<?php

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

/** A ServerOps whose single `run` answers with this stdout/stderr. */
function dockerOps(string $stdout, string $stderr = '', bool $answered = true): ServerOps
{
    $process = Mockery::mock(ProcessResult::class);
    $process->shouldReceive('output')->andReturn($stdout);
    $process->shouldReceive('errorOutput')->andReturn($stderr);

    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturn(new ServerOpsResult(
        ok: $answered && $stderr === '',
        reference: 'ref-1',
        result: $process,
        answered: $answered,
    ));

    return $ops;
}

it('asks the daemon, not the client binary', function () {
    // `docker --version` reads straight off the client and succeeds with the
    // daemon stopped — it answers "is the package installed" while appearing
    // to answer "does Docker work". The check must use a command that talks to
    // the daemon.
    $ops = Mockery::mock(ServerOps::class);
    $seen = null;

    $ops->shouldReceive('run')->andReturnUsing(function (array $command) use (&$seen) {
        $seen = $command;

        return new ServerOpsResult(ok: false, reference: 'r', answered: false);
    });

    (new DockerCheck($ops))->run();

    expect($seen)->toContain('info')
        ->and($seen)->not->toContain('--version');
});

it('separates not-installed, not-running and not-permitted', function () {
    // Three failures with three different answers. The generic "Docker is not
    // working" sends someone to reinstall a daemon that is running fine.
    $missing = (new DockerCheck(dockerOps('', 'docker: command not found')))->run();
    $denied = (new DockerCheck(dockerOps('', 'permission denied while trying to connect')))->run();
    $down = (new DockerCheck(dockerOps('', 'Cannot connect to the Docker daemon')))->run();

    expect($missing['status'])->toBe('warn')
        ->and($missing['fix'])->toBe('doctor.fixes.docker_missing')
        ->and($denied['status'])->toBe('fail')
        ->and($denied['fix'])->toBe('doctor.fixes.docker_denied')
        ->and($down['status'])->toBe('fail')
        ->and($down['fix'])->toBe('doctor.fixes.docker_down');
});

it('passes when the daemon answers with a version', function () {
    $result = (new DockerCheck(dockerOps("27.3.1\n")))->run();

    expect($result['status'])->toBe('pass')
        ->and($result['detail'])->toContain('27.3.1');
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
    $ops->shouldReceive('run')->andReturn(new ServerOpsResult(ok: false, reference: 'r', answered: false));

    expect((new DockerExposureCheck($ops))->run()['status'])->toBe('pass');
});

it('translates both checks and every fix in every locale', function () {
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        foreach (['docker', 'docker_exposure'] as $key) {
            expect(__("doctor.checks.{$key}", [], $locale))
                ->not->toBe("doctor.checks.{$key}", "check name {$key} missing in {$locale}");
        }

        foreach (['docker_missing', 'docker_down', 'docker_denied', 'docker_exposure'] as $key) {
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
