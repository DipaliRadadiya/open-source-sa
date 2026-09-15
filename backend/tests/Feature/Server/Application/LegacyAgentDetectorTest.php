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
    $pid = 11684;

    Process::fake(function ($p) use ($listening, $unit, $binary, $pid) {
        $args = ($p->command[0] ?? '') === 'sudo' ? array_slice((array) $p->command, 2) : (array) $p->command;
        $line = implode(' ', $args);

        // Real `ss -ltnpH` output shape, copied from a live v7 box.
        if (($args[0] ?? '') === 'ss') {
            return Process::result(output: $listening
                ? "LISTEN 0 4096 *:43210 *:* users:((\"sureshcloud-age\",pid={$pid},fd=5))\n"
                    ."LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n"
                : "LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n");
        }

        // systemd's own answer to "which unit owns this PID".
        if (($args[0] ?? '') === 'cat' && str_contains($line, '/proc/')) {
            return $unit === null
                ? Process::result(exitCode: 1, output: '')
                : Process::result(output: "0::/system.slice/{$unit}\n");
        }

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: $binary !== null && $args[2] === $binary ? 0 : 1, output: '');
        }

        return Process::result(output: '');
    });
}

it('finds a white-labelled agent no name list would have caught', function () {
    // A real v7 box runs its agent as `sureshcloud.service`. The unit name is
    // a build-time `-X main.ServiceName=…`, so matching names cannot work; the
    // port is hardcoded in the agent's own source and the PID names its unit.
    agentFake(['listening' => true, 'unit' => 'sureshcloud.service']);

    $detected = app(LegacyAgentDetector::class)->describe();

    expect($detected['running'])->toBeTrue()
        ->and($detected['port'])->toBeTrue()
        ->and($detected['unit'])->toBe('sureshcloud.service');
});

it('still reports running when the unit cannot be resolved', function () {
    // Something holds the port but /proc says nothing useful — a container, a
    // stray binary. Running is still the honest answer; there is just no unit
    // to stop.
    agentFake(['listening' => true, 'unit' => null]);

    $detected = app(LegacyAgentDetector::class)->describe();

    expect($detected['running'])->toBeTrue()
        ->and($detected['unit'])->toBeNull();
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

it('never identifies PM2\'s own boot unit as the agent', function () {
    // Should be impossible now that the unit is derived from whatever holds
    // 43210 — but the guard stays, because a caller stops what this reports and
    // `pm2-<user>.service` is the boot hook every adopted application needs.
    agentFake(['listening' => true, 'unit' => 'pm2-appuser.service']);

    expect(app(LegacyAgentDetector::class)->describe()['unit'])->toBeNull();
});

it('treats an unreadable server as not-detected rather than crashing', function () {
    // `ss` missing, systemctl refusing — a check that throws would block
    // adoption on every server where it could not answer.
    Process::fake(fn () => Process::result(exitCode: 127, errorOutput: 'not found', output: ''));

    expect(app(LegacyAgentDetector::class)->running())->toBeFalse();
});
