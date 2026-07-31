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

        $unit = $written[0] ?? '';

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
