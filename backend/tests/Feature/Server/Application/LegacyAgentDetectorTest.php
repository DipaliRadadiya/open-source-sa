<?php

use App\Services\Server\Applications\LegacyAgentDetector;
use Illuminate\Support\Facades\Process;

/**
 * Adoption has to know whether the old panel is still driving this server.
 *
 * Two panels managing one set of applications is not a millisecond race — PM2
 * serialises its own commands — it is two control planes acting on the same
 * state. The concrete loss: the old agent runs `pm2 cleardump` when any of a
 * user's applications is deleted, which empties that user's whole dump, which
 * is what adopted applications rely on to come back at boot.
 */
function agentFake(array $options = []): void
{
    $listening = $options['listening'] ?? false;
    $unit = $options['unit'] ?? null;
    $binary = $options['binary'] ?? null;

    Process::fake(function ($p) use ($listening, $unit, $binary) {
        $args = ($p->command[0] ?? '') === 'sudo' ? array_slice((array) $p->command, 2) : (array) $p->command;
        $line = implode(' ', $args);

        if (($args[0] ?? '') === 'ss') {
            return Process::result(output: $listening
                ? "LISTEN 0 4096 *:43210 *:*\nLISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n"
                : "LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n");
        }

        if (str_contains($line, 'list-units')) {
            return Process::result(output: $unit === null
                ? "nginx.service loaded active running A high performance web server\n"
                : "nginx.service loaded active running A high performance web server\n{$unit} loaded active running Legacy agent\n");
        }

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: $binary !== null && $args[2] === $binary ? 0 : 1, output: '');
        }

        return Process::result(output: '');
    });
}

it('finds the agent by its port, which every build shares', function () {
    // The unit name comes from a build-time `-X main.ServiceName=…`, so it
    // differs on white-labelled builds. The listener is hardcoded to 43210 in
    // the agent's own main.go and is the same everywhere.
    agentFake(['listening' => true]);

    expect(app(LegacyAgentDetector::class)->running())->toBeTrue();
});

it('finds it by unit when the name matches, port or not', function () {
    agentFake(['unit' => 'serveravatar.service']);

    $detected = app(LegacyAgentDetector::class)->describe();

    expect($detected['running'])->toBeTrue()
        ->and($detected['unit'])->toBe('serveravatar.service')
        ->and($detected['port'])->toBeFalse();
});

it('says nothing is running on a server the old panel never touched', function () {
    agentFake();

    expect(app(LegacyAgentDetector::class)->running())->toBeFalse();

    expect(app(LegacyAgentDetector::class)->describe())->toMatchArray([
        'running' => false,
        'port' => false,
        'unit' => null,
        'binary' => null,
    ]);
});

it('reports an installed-but-stopped agent, which is the state adoption wants', function () {
    // Someone has already taken the old panel out of the picture. Saying
    // "nothing found" would leave them unsure whether the check even looked.
    agentFake(['binary' => '/usr/local/bin/serveravatar-agent']);

    $detected = app(LegacyAgentDetector::class)->describe();

    expect($detected['running'])->toBeFalse()
        ->and($detected['binary'])->toBe('/usr/local/bin/serveravatar-agent');
});

it('does not mistake another service on another port for the agent', function () {
    Process::fake(fn ($p) => ($p->command[0] ?? '') === 'ss'
        // 43211 is the agent's internal proxy target, and 4321 shares a prefix.
        ? Process::result(output: "LISTEN 0 511 0.0.0.0:4321 0.0.0.0:*\nLISTEN 0 511 127.0.0.1:43211 0.0.0.0:*\n")
        : Process::result(output: ''));

    expect(app(LegacyAgentDetector::class)->running())->toBeFalse();
});

it('treats an unreadable server as not-detected rather than crashing', function () {
    // `ss` missing, systemctl refusing — a check that throws would block
    // adoption on every server where it could not answer.
    Process::fake(fn () => Process::result(exitCode: 127, errorOutput: 'not found', output: ''));

    expect(app(LegacyAgentDetector::class)->running())->toBeFalse();
});
