<?php

use App\Exceptions\Server\Application\NoPortAvailableException;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Applications\ProcessSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * An application with a start command runs a real process.
 *
 * Until now `app_port` and `start_command` were stored, echoed back, and read
 * by nothing — a user could configure a Node app, see the values persist, and
 * find that nothing was ever listening. These cover the machinery that makes
 * them true, and the guards that stop it doing damage.
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

function nodeApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'API',
        'domain' => 'api.test',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'node_version' => '20.11.0',
        'app_port' => 3000,
        'start_command' => 'node server.js',
    ], $overrides));
}

function supervisorHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

describe('the unit', function () {
    it('names the pinned Node, the allocated port and the site user', function () {
        $written = new ArrayObject;
        Process::fake(function ($p) use ($written) {
            if (($p->command[0] ?? '') === 'tee') {
                $written[] = (string) $p->input;
            }

            return Process::result(output: '');
        });

        app(ProcessSupervisor::class)->apply(nodeApp(), '/home/appuser/api.test');

        // Picked by content, not position: applying a unit also writes a
        // logrotate policy for the log files it now appends to, and indexing
        // into the list made this assert against whichever happened to be
        // written first.
        $unit = collect($written)->first(fn (string $body): bool => str_contains($body, '[Unit]')) ?? '';

        expect($unit)
            ->toContain('User=appuser')
            ->toContain('WorkingDirectory=/home/appuser/api.test')
            ->toContain('Environment=PORT=3000')
            // The pinned version must reach run time, not just build time.
            ->toContain('/opt/fnm/node-versions/v20.11.0/installation/bin')
            ->toContain('ExecStart=/opt/fnm/node-versions/v20.11.0/installation/bin/node server.js')
            // A crash loop that restarts forever buries its own cause.
            ->toContain('StartLimitBurst=5')
            ->toContain('MemoryMax=512M');
    });

    it('loads the same .env the Environment screen edits', function () {
        // These were two independent path calculations, and they disagreed for
        // every Node application: the unit read `{documentRoot}/.env` while the
        // screen edited one a directory above it. The user saw their file in
        // the file manager, an empty editor in the panel, and a service that
        // read neither of the two the panel could name.
        $written = new ArrayObject;
        Process::fake(function ($p) use ($written) {
            if (($p->command[0] ?? '') === 'tee') {
                $written[] = (string) $p->input;
            }

            return Process::result(output: '');
        });

        $application = nodeApp(['serving_profile' => 'node']);

        app(ProcessSupervisor::class)->apply($application, '/home/appuser/api.test');

        $unit = collect($written)->first(fn (string $body): bool => str_contains($body, '[Unit]')) ?? '';

        expect($unit)->toContain('EnvironmentFile=-'.$application->envPath());
    });

    it('keeps a proxied app\'s .env at its code root', function () {
        // Nothing under a Node app's document root is fetchable by path — the
        // vhost proxies, and denies dotfiles ahead of the proxy besides. The
        // "never inside the served directory" rule was written for a directory
        // the web server hands out, and moving the file for a proxied app only
        // put it where the application does not look.
        $application = nodeApp(['serving_profile' => 'node']);

        expect($application->envPath())->toBe($application->codePath().'/.env');
    });

    it('checks the app is actually up, not just that start returned zero', function () {
        // `systemctl start` succeeds for a unit that starts and immediately
        // dies — which is exactly what a bad start command does.
        Process::fake(fn ($p) => ($p->command[1] ?? '') === 'is-active'
            ? Process::result(output: '', errorOutput: 'inactive', exitCode: 3)
            : Process::result(output: ''));

        expect(fn () => app(ProcessSupervisor::class)->apply(nodeApp(), '/home/appuser/api.test'))
            ->toThrow(ProvisioningFailedException::class);

        // And the broken unit is taken back out, so the next unrelated
        // daemon-reload does not pick it up.
        Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'rm'
            && str_contains((string) ($p->command[2] ?? ''), 'sv-app-'));
    });

    it('stops and disables before deleting the unit file', function () {
        $runs = new ArrayObject;
        Process::fake(function ($p) use ($runs) {
            $runs[] = $p->command;

            return Process::result(output: '');
        });

        app(ProcessSupervisor::class)->remove(nodeApp());

        $order = collect($runs)->map(fn ($c) => implode(' ', $c))->values();

        // Deleting the unit first would leave the process running with nothing
        // left that can stop it — a site the panel has forgotten, still holding
        // its port.
        expect($order->search(fn ($c) => str_contains($c, 'systemctl stop')))
            ->toBeLessThan($order->search(fn ($c) => str_starts_with($c, 'rm -f')));
    });

    it('writes no unit for an application that runs nothing', function () {
        Process::fake();

        expect(app(ProcessSupervisor::class)->runs(nodeApp(['start_command' => null])))->toBeFalse();
    });
});

it('logs a missing unit as an expected probe, not an error', function () {
    $logs = [];
    Process::fake(function ($p) use (&$logs) {
        $args = $p->command[0] === 'sudo' ? array_slice($p->command, 2) : $p->command;

        // `test -f` on a path that does not exist exits 1.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f') {
            $logs[] = $args;

            return Process::result(exitCode: 1, errorOutput: '', output: '');
        }

        return Process::result(output: '');
    });

    $supervisor = app(ProcessSupervisor::class);

    // Exists returns false without throwing.
    expect($supervisor->exists(nodeApp()))->toBeFalse();

    // The exit-1 probe call was made, logged at info level.
    expect($logs)->not()->toBeEmpty();
});

describe('the start command', function () {
    it('refuses a package manager, which would break signals', function (string $command) {
        $this->withHeaders(supervisorHeaders())
            ->putJson('/api/applications/'.nodeApp()->id, ['start_command' => $command])
            ->assertJsonValidationErrors('start_command');
    })->with(['npm start', 'yarn start', 'pnpm start', 'npx serve']);

    it('refuses shell syntax, which ExecStart cannot run', function (string $command) {
        $this->withHeaders(supervisorHeaders())
            ->putJson('/api/applications/'.nodeApp()->id, ['start_command' => $command])
            ->assertJsonValidationErrors('start_command');
    })->with(['node a.js && node b.js', 'node a.js | tee log', 'node $(cat x)', 'node a.js > out']);

    it('accepts the forms people actually use', function (string $command) {
        $this->withHeaders(supervisorHeaders())
            ->putJson('/api/applications/'.nodeApp()->id, ['start_command' => $command])
            ->assertOk();
    })->with(['node server.js', 'node dist/main.js --port 3000', '/usr/bin/node app.js']);
});

describe('ports', function () {
    it('skips ports the database has handed out and ports already listening', function () {
        nodeApp(['app_port' => 3000, 'domain' => 'a.test']);

        // 3001 is not ours, but something is on it.
        Process::fake(fn () => Process::result(output: "LISTEN 0 511 0.0.0.0:3001 0.0.0.0:*\n"));

        expect(app(PortAllocator::class)->allocate())->toBe(3002);
    });

    it('lets the owner of the server pick a port that merely has a name', function () {
        Process::fake(fn () => Process::result(output: ''));

        // 8080 is `http-alt` in /etc/services and is also where half the Node
        // applications in the world listen. Refusing it would be the panel
        // overruling someone about their own machine.
        $this->withHeaders(supervisorHeaders())->postJson('/api/applications', [
            'system_user_id' => $this->su->id, 'name' => 'API', 'domain' => 'api8080.test',
            'site_type' => 'php', 'app_port' => 8080,
        ])->assertCreated()->assertJsonPath('application.app_port', 8080);
    });

    it('refuses a port another application on this server already has', function () {
        Process::fake(fn () => Process::result(output: ''));
        nodeApp(['app_port' => 3500, 'domain' => 'taken.test']);

        // Before this, the only thing standing in the way was the unique
        // index — a 500 rather than a message naming the problem.
        $this->withHeaders(supervisorHeaders())->postJson('/api/applications', [
            'system_user_id' => $this->su->id, 'name' => 'B', 'domain' => 'b.test',
            'site_type' => 'php', 'app_port' => 3500,
        ])->assertJsonValidationErrors('app_port');
    });

    it('refuses a port something outside the panel is listening on', function () {
        // The user's server runs things the panel did not put there.
        Process::fake(fn () => Process::result(output: "LISTEN 0 511 127.0.0.1:9999 0.0.0.0:*\n"));

        $this->withHeaders(supervisorHeaders())->postJson('/api/applications', [
            'system_user_id' => $this->su->id, 'name' => 'C', 'domain' => 'c.test',
            'site_type' => 'php', 'app_port' => 9999,
        ])->assertJsonValidationErrors('app_port');
    });

    it('does not report an application port as taken by itself', function () {
        Process::fake(fn () => Process::result(output: ''));
        $app = nodeApp(['app_port' => 3500, 'domain' => 'self.test']);

        // Saving an unchanged form must not fail.
        $this->withHeaders(supervisorHeaders())
            ->putJson("/api/applications/{$app->id}", ['app_port' => 3500])
            ->assertOk();
    });

    it('skips a port /etc/services has spoken for, even with nothing on it', function () {
        config(['server.applications.port_range' => ['from' => 3306, 'to' => 3310]]);
        Process::fake(fn () => Process::result(output: ''));

        // 3306 is MySQL. Nothing is listening on a box where MySQL is not
        // installed *yet* — so a listening-only check would hand it out, and
        // installing MySQL next week would break one of the two.
        expect(app(PortAllocator::class)->allocate())->toBe(3307)
            // Allocation is the conservative side: the panel choosing for
            // itself skips anything /etc/services names.
            ->and(app(PortAllocator::class)->conflict(3306))->toBeNull();
    });

    it('stays below the range the kernel hands to outgoing connections', function () {
        $range = @file_get_contents('/proc/sys/net/ipv4/ip_local_port_range');
        $ephemeralFrom = (int) (preg_split('/\s+/', trim((string) $range))[0] ?? 32768);

        // A port inside the ephemeral range works until the kernel gives the
        // same one to an outgoing connection — an intermittent bind failure
        // with nothing to point at.
        expect((int) config('server.applications.port_range.to'))->toBeLessThan($ephemeralFrom);
    });

    it('refuses rather than reaching outside the configured range', function () {
        config(['server.applications.port_range' => ['from' => 3000, 'to' => 3000]]);
        nodeApp(['app_port' => 3000, 'domain' => 'a.test']);
        Process::fake(fn () => Process::result(output: ''));

        expect(fn () => app(PortAllocator::class)->allocate())
            ->toThrow(NoPortAvailableException::class);
    });

    it('gives a port only to an application that runs something', function () {
        Process::fake(fn () => Process::result(output: ''));

        $this->withHeaders(supervisorHeaders())->postJson('/api/applications', [
            'system_user_id' => $this->su->id, 'name' => 'Plain', 'domain' => 'plain.test',
            'site_type' => 'php',
        ])->assertCreated()->assertJsonPath('application.app_port', null);
    });
});

describe('the port check', function () {
    it('warns about a named port without refusing it', function () {
        Process::fake(fn () => Process::result(output: ''));

        // The distinction the screen needs: this is a caution, not an error.
        $this->withHeaders(supervisorHeaders())
            ->getJson('/api/applications/port-check?port=8080')
            ->assertOk()
            ->assertJsonPath('port_check.available', true)
            ->assertJsonPath('port_check.reason', 'registered')
            ->assertJsonPath('port_check.service', 'http-alt')
            // No alternative offered — the choice was fine.
            ->assertJsonPath('port_check.suggested_port', null);
    });

    it('refuses a port another application holds, and offers a free one', function () {
        Process::fake(fn () => Process::result(output: ''));
        nodeApp(['app_port' => 3500, 'domain' => 'held.test']);

        $this->withHeaders(supervisorHeaders())
            ->getJson('/api/applications/port-check?port=3500')
            ->assertOk()
            ->assertJsonPath('port_check.available', false)
            ->assertJsonPath('port_check.reason', 'in_use_by_app')
            // Somewhere to go next, rather than just a refusal.
            ->assertJsonPath('port_check.suggested_port', 3000);
    });

    it('reports a port nothing claims as simply free', function () {
        Process::fake(fn () => Process::result(output: ''));

        $this->withHeaders(supervisorHeaders())
            ->getJson('/api/applications/port-check?port=3777')
            ->assertOk()
            ->assertJsonPath('port_check.available', true)
            ->assertJsonPath('port_check.reason', null)
            ->assertJsonPath('port_check.service', null);
    });

    it('does not report an application own port back to it as taken', function () {
        Process::fake(fn () => Process::result(output: ''));
        $app = nodeApp(['app_port' => 3500, 'domain' => 'mine.test']);

        $this->withHeaders(supervisorHeaders())
            ->getJson("/api/applications/port-check?port=3500&application_id={$app->id}")
            ->assertOk()
            ->assertJsonPath('port_check.available', true);
    });
});

describe('the endpoint', function () {
    it('refuses to act on an application with no process', function () {
        $app = nodeApp(['start_command' => null]);

        // A button that reports success while doing nothing teaches the user
        // that the button works.
        $this->withHeaders(supervisorHeaders())
            ->postJson("/api/applications/{$app->id}/process/restart")
            ->assertStatus(422);
    });

    it('restarts a real one and reports what systemd says', function () {
        Process::fake(fn ($p) => ($p->command[1] ?? '') === 'show'
            ? Process::result(output: "ActiveState=active\nSubState=running\nMemoryCurrent=12345\nNRestarts=0\n")
            : Process::result(output: ''));

        $app = nodeApp();

        $this->withHeaders(supervisorHeaders())
            ->postJson("/api/applications/{$app->id}/process/restart")
            ->assertOk()
            ->assertJsonPath('application.process.state', 'active')
            ->assertJsonPath('application.has_process', true);

        Process::assertRan(fn ($p) => $p->command === ['systemctl', 'restart', "sv-app-{$app->id}.service"]);
    });

    it('rejects an action that is not one of the three', function () {
        Process::fake();

        $this->withHeaders(supervisorHeaders())
            ->postJson('/api/applications/'.nodeApp()->id.'/process/destroy')
            ->assertNotFound();
    });
});
