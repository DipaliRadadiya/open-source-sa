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
    $fail = $options['fail'] ?? null;
    $logrotate = $options['logrotate'] ?? false;

    Process::fake(function ($p) use ($ran, $written, $agentRunning, $bootEnabled, $pm2Present, $fail, $logrotate) {
        $args = ($p->command[0] ?? '') === 'sudo' ? array_slice((array) $p->command, 2) : (array) $p->command;
        $line = implode(' ', $args);
        $ran[] = $line;

        if ($fail !== null && str_contains($line, $fail)) {
            return Process::result(exitCode: 1, errorOutput: 'refused');
        }

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: $logrotate ? 0 : 1);
        }

        if (($args[0] ?? '') === 'tee') {
            $written[] = (string) $p->input;

            return Process::result(output: '');
        }

        // The agent is found by whatever holds port 43210, then that PID names
        // its own unit. Shapes copied from a live v7 box, where the agent runs
        // as `sureshcloud.service` — a name no pattern would have matched.
        if (($args[0] ?? '') === 'ss') {
            return Process::result(output: $agentRunning
                ? "LISTEN 0 4096 *:43210 *:* users:((\"sureshcloud-age\",pid=11684,fd=5))\n"
                : "LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n");
        }

        if (($args[0] ?? '') === 'cat' && str_contains($line, '/proc/')) {
            return $agentRunning
                ? Process::result(output: "0::/system.slice/sureshcloud.service\n")
                : Process::result(exitCode: 1, output: '');
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

    expect($result['agent']['unit'])->toBe('sureshcloud.service')
        ->and(collect($f['ran'])->contains(fn (string $c) => $c === 'systemctl stop sureshcloud.service'))->toBeTrue()
        ->and(collect($f['ran'])->contains(fn (string $c) => $c === 'systemctl disable sureshcloud.service'))->toBeTrue();

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

    expect(array_column($result['users'], 'username'))->toBe(['appuser']);
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
        ->assertJsonPath('agent.unit', 'sureshcloud.service')
        ->assertJsonPath('users.0.username', 'appuser');
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

it('leaves an activity entry with a sentence, not its key', function () {
    // The docker/pm2 merge logged this event with no sentence, so the
    // Activity Log showed `activity.sync.legacy_handover`.
    adopted();
    handoverFake();

    $headers = ['Authorization' => 'Bearer '.test()->token];

    $this->withHeaders($headers)->postJson('/api/server/sync/handover')->assertOk();

    $entry = collect($this->withHeaders($headers)->getJson('/api/admin/activity-log')->assertOk()->json('activity_log'))
        ->firstWhere('action', 'legacy_handover');

    expect($entry)->not->toBeNull()
        ->and($entry['description'])->not->toStartWith('activity.');
});

describe('honest results (frontend QA FS-C26, FS-C27)', function () {
    function handoverHeaders(): array
    {
        $admin = User::factory()->admin()->create();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    it('answers 500 with the failed step when the old agent will not stop', function () {
        handoverFake(['fail' => 'systemctl stop sureshcloud']);
        adopted();

        $this->withHeaders(handoverHeaders())
            ->postJson('/api/server/sync/handover')
            ->assertStatus(500)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('agent.stopped', false)
            ->assertJsonPath('agent.disabled', true);
    });

    it('reports a boot unit that could not be enabled, per user', function () {
        handoverFake(['fail' => 'systemctl enable pm2-appuser']);
        adopted();

        $this->withHeaders(handoverHeaders())
            ->postJson('/api/server/sync/handover')
            ->assertStatus(500)
            ->assertJsonPath('users.0.username', 'appuser')
            ->assertJsonPath('users.0.boot_unit', 'failed')
            ->assertJsonPath('users.0.log_rotation', 'ok');
    });

    it('answers 200 with every step when all of them worked', function () {
        handoverFake();
        adopted();

        $this->withHeaders(handoverHeaders())
            ->postJson('/api/server/sync/handover')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('users.0.boot_unit', 'written');
    });

    it('says whether a handover is still needed, without changing anything', function () {
        $f = handoverFake();
        adopted();

        $this->withHeaders(handoverHeaders())
            ->getJson('/api/server/sync/handover')
            ->assertOk()
            ->assertJsonPath('needed', true)
            ->assertJsonPath('agent.running', true)
            ->assertJsonPath('users.0.boot_unit_healthy', false)
            ->assertJsonPath('users.0.log_rotation', false);

        expect(collect($f['ran'])->contains(fn (string $c) => str_contains($c, 'systemctl stop') || str_contains($c, 'systemctl enable')))->toBeFalse()
            ->and($f['written'])->toHaveCount(0);

        handoverFake(['agent' => false, 'boot_enabled' => true, 'logrotate' => true]);

        $this->withHeaders(handoverHeaders())
            ->getJson('/api/server/sync/handover')
            ->assertJsonPath('needed', false);
    });
});
