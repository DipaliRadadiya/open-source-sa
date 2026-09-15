<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\LegacyHandover;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The server-side half of adoption.
 *
 * Sync records what it finds and writes nothing to the server. These are the
 * three writes the migration still needs, behind an action someone chooses —
 * and none of them may restart a customer's site.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'mern', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'appuser', 'home_path' => '/home/appuser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function adopted(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'API',
        'domain' => 'api.test',
        'site_type' => 'git',
        'serving_profile' => 'node',
        'status' => 'active',
        'web_root' => '/',
        'node_version' => '20.11.0',
        'start_command' => 'node server.js',
        'supervisor_mode' => 'pm2',
        'pm2_process_name' => 'legacy-api',
    ], $overrides));
}

/**
 * @return array{ran: ArrayObject<int, string>, written: ArrayObject<int, string>}
 */
function handoverFake(array $options = []): array
{
    $ran = new ArrayObject;
    $written = new ArrayObject;

    $agentRunning = $options['agent'] ?? true;
    $bootEnabled = $options['boot_enabled'] ?? false;
    $pm2Present = $options['pm2_present'] ?? true;

    Process::fake(function ($p) use ($ran, $written, $agentRunning, $bootEnabled, $pm2Present) {
        $args = ($p->command[0] ?? '') === 'sudo' ? array_slice((array) $p->command, 2) : (array) $p->command;
        $line = implode(' ', $args);
        $ran[] = $line;

        if (($args[0] ?? '') === 'tee') {
            $written[] = (string) $p->input;

            return Process::result(output: '');
        }

        if (str_contains($line, 'list-units')) {
            return Process::result(output: $agentRunning
                ? "nginx.service loaded active running Web\nserveravatar.service loaded active running Agent\n"
                : "nginx.service loaded active running Web\n");
        }

        if (($args[0] ?? '') === 'ss') {
            return Process::result(output: "LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n");
        }

        if (str_contains($line, 'is-enabled')) {
            return Process::result(exitCode: $bootEnabled ? 0 : 1, output: '');
        }

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-x') {
            return Process::result(exitCode: $pm2Present ? 0 : 1, output: '');
        }

        return Process::result(output: '');
    });

    return ['ran' => $ran, 'written' => $written];
}

it('stops the old agent without going through the old agent', function () {
    adopted();

    $f = handoverFake();

    $result = app(LegacyHandover::class)->complete();

    expect($result['agent'])->toBe('serveravatar.service')
        ->and(collect($f['ran'])->contains(fn (string $c) => $c === 'systemctl stop serveravatar.service'))->toBeTrue()
        ->and(collect($f['ran'])->contains(fn (string $c) => $c === 'systemctl disable serveravatar.service'))->toBeTrue();

    // Never its own teardown: `pm2 unstartup` and `pm2 kill` would stop every
    // application the user owns. The applications are children of the PM2
    // daemon, not of the agent.
    expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'unstartup')))->toBeFalse()
        ->and(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'pm2 kill')))->toBeFalse();
});

it('restarts nothing, because the sites are live', function () {
    adopted();

    $f = handoverFake();

    app(LegacyHandover::class)->complete();

    foreach ($f['ran'] as $command) {
        expect($command)->not->toContain('systemctl restart')
            ->and($command)->not->toContain('pm2 restart')
            ->and($command)->not->toContain('pm2 resurrect');
    }
});

it('writes the boot unit itself rather than scraping pm2 startup', function () {
    adopted();

    $f = handoverFake(['boot_enabled' => false]);

    app(LegacyHandover::class)->complete();

    $unit = collect($f['written'])->first(fn (string $c) => str_contains($c, 'pm2 resurrect'));

    // `pm2 startup` prints a sudo line for a human to paste. The old panel
    // scraped it with a regex that a Node upgrade or a hyphenated username
    // defeats — and then ran the empty match, which exits 0.
    expect($unit)->not->toBeNull()
        ->and($unit)->toContain('Environment=PM2_HOME=/home/appuser/.pm2')
        ->and($unit)->toContain('User=appuser');

    expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'systemctl enable pm2-appuser.service')))->toBeTrue();
    expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'startup')))->toBeFalse();
});

it('enables the boot unit but does not start it', function () {
    adopted();

    $f = handoverFake(['boot_enabled' => false]);

    app(LegacyHandover::class)->complete();

    // The daemon is already running — that is what is serving the sites. Its
    // ExecStart is `pm2 resurrect`, which against a live daemon would start a
    // second copy of every application in the dump.
    expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'systemctl start pm2-appuser')))->toBeFalse();
});

it('leaves a working boot unit alone', function () {
    adopted();

    $f = handoverFake(['boot_enabled' => true, 'pm2_present' => true]);

    app(LegacyHandover::class)->complete();

    // A customer's own `pm2 startup` is not ours to rewrite.
    expect(collect($f['written'])->contains(fn (string $c) => str_contains($c, 'pm2 resurrect')))->toBeFalse();
});

it('rewrites a boot unit whose pm2 a Node upgrade moved', function () {
    adopted();

    // Enabled, but its binary is gone — which is what `n` does to a unit
    // written when PM2 lived under /usr/lib. It fails at boot, silently.
    $f = handoverFake(['boot_enabled' => true, 'pm2_present' => false]);

    app(LegacyHandover::class)->complete();

    expect(collect($f['written'])->contains(fn (string $c) => str_contains($c, 'pm2 resurrect')))->toBeTrue();
});

it('gives PM2 the log rotation it has never had', function () {
    adopted();

    $f = handoverFake();

    app(LegacyHandover::class)->complete();

    $policy = collect($f['written'])->first(fn (string $c) => str_contains($c, '/.pm2/logs/'));

    // The old panel installed pm2-logrotate into root's daemon while every
    // application runs under a per-user one, so this has never rotated. On an
    // old box with a chatty application it is what fills the disk.
    expect($policy)->not->toBeNull()
        ->and($policy)->toContain('/home/appuser/.pm2/logs/*.log')
        // PM2 holds its logs open for the life of the daemon.
        ->and($policy)->toContain('copytruncate')
        ->and($policy)->toContain('su appuser appuser');
});

it('touches only accounts that actually have an adopted application', function () {
    adopted();

    SystemUser::create([
        'username' => 'unrelated', 'home_path' => '/home/unrelated',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $f = handoverFake();

    $result = app(LegacyHandover::class)->complete();

    expect($result['users'])->toBe(['appuser']);
    expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'unrelated')))->toBeFalse();
});

it('is a no-op worth running on a server the old panel never touched', function () {
    $f = handoverFake(['agent' => false]);

    $result = app(LegacyHandover::class)->complete();

    expect($result['agent'])->toBeNull()
        ->and($result['users'])->toBe([]);
});

it('is reachable by someone who can manage sync', function () {
    adopted();
    handoverFake();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/server/sync/handover')
        ->assertOk()
        ->assertJsonPath('agent', 'serveravatar.service')
        ->assertJsonPath('users', ['appuser']);
});

it('is refused to someone who cannot', function () {
    // Separate test, not a second request in the one above: the auth guard
    // caches the user it resolved, so a follow-up call in the same test is
    // still answered as whoever went first.
    adopted();
    $f = handoverFake();

    $viewer = User::factory()->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson('/api/server/sync/handover')
        ->assertForbidden();

    expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'systemctl')))->toBeFalse();
});
