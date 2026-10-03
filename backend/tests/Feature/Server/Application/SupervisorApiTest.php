<?php

use App\Enums\SupervisorMode;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The API surface for how an application is supervised.
 *
 * Two things reach a user here: the worker count on the ordinary update, and a
 * separate endpoint for moving an adopted application onto a unit. The second
 * is separate precisely because it restarts the application.
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

function apiApp(array $overrides = []): Application
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
        'app_port' => 3000,
        'start_command' => 'node server.js',
    ], $overrides));
}

function asAdmin(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function healthyServer(): void
{
    Process::fake(fn ($p) => ($p->command[0] ?? '') === 'curl'
        ? Process::result(output: '200')
        : Process::result(output: '[]'));
}

describe('the worker count', function () {
    it('is accepted with a script PM2 can fork', function () {
        Process::fake();

        $application = apiApp();

        $this->withHeaders(asAdmin())
            ->putJson("/api/applications/{$application->id}", ['process_instances' => 4])
            ->assertOk()
            ->assertJsonPath('application.process_instances', 4);

        // The resource echoing it back is not the same as it being stored.
        expect($application->fresh()->process_instances)->toBe(4);
    });

    it('is refused when the start command names no script', function () {
        Process::fake();

        // PM2 clusters by forking a JavaScript file. Pointed at anything else
        // it runs one process and reports success — which is how the old
        // panel's users chose four instances and got one, with no error.
        $application = apiApp(['start_command' => '/usr/local/bin/nodebb']);

        $this->withHeaders(asAdmin())
            ->putJson("/api/applications/{$application->id}", ['process_instances' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('process_instances');
    });

    it('reads the start command being set in the same request', function () {
        Process::fake();

        // The stored value is unforkable, the incoming one is fine. Judging on
        // the stored value would refuse a change that is about to make it true.
        $application = apiApp(['start_command' => '/usr/local/bin/nodebb']);

        $this->withHeaders(asAdmin())
            ->putJson("/api/applications/{$application->id}", [
                'start_command' => 'node server.js',
                'process_instances' => 2,
            ])
            ->assertOk();
    });

    it('is bounded, because MemoryMax is divided across the workers', function () {
        Process::fake();

        $application = apiApp();

        $this->withHeaders(asAdmin())
            ->putJson("/api/applications/{$application->id}", ['process_instances' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('process_instances');
    });

    it('cannot be set by someone who cannot manage applications', function () {
        Process::fake();

        $viewer = User::factory()->create();
        $token = $viewer->createToken('t')->plainTextToken;
        $application = apiApp();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson("/api/applications/{$application->id}", ['process_instances' => 4])
            ->assertForbidden();

        expect($application->fresh()->process_instances)->toBeNull();
    });
});

describe('converting to a unit', function () {
    it('moves an adopted application and reports the new mode', function () {
        healthyServer();

        $application = apiApp(['supervisor_mode' => 'pm2', 'pm2_process_name' => 'legacy-api']);

        $this->withHeaders(asAdmin())
            ->postJson("/api/applications/{$application->id}/supervisor/convert")
            ->assertOk()
            ->assertJsonPath('application.supervisor_mode', 'systemd');

        expect($application->fresh()->supervisor_mode)->toBe(SupervisorMode::Systemd);
    });

    it('refuses an application that already runs under a unit', function () {
        Process::fake();

        $application = apiApp();

        $this->withHeaders(asAdmin())
            ->postJson("/api/applications/{$application->id}/supervisor/convert")
            ->assertStatus(422);

        Process::assertNothingRan();
    });

    it('refuses one the old panel left without a runnable entrypoint', function () {
        Process::fake();

        // v7 stored `npm run start` and rewrote it on the way to PM2, so there
        // may be nothing that can go in an ExecStart. Guessing one by reading
        // package.json is how an adopted site fails to come back.
        $application = apiApp([
            'supervisor_mode' => 'pm2',
            'pm2_process_name' => 'legacy-api',
            'start_command' => null,
        ]);

        $this->withHeaders(asAdmin())
            ->postJson("/api/applications/{$application->id}/supervisor/convert")
            ->assertStatus(422);

        Process::assertNothingRan();
    });

    it('reports a rollback rather than leaving the caller guessing', function () {
        // The unit comes up and answers 502. The application is already back
        // under PM2 and running by the time this response is written.
        Process::fake(fn ($p) => ($p->command[0] ?? '') === 'curl'
            ? Process::result(output: '502')
            : Process::result(output: '[]'));

        $application = apiApp(['supervisor_mode' => 'pm2', 'pm2_process_name' => 'legacy-api']);

        $this->withHeaders(asAdmin())
            ->postJson("/api/applications/{$application->id}/supervisor/convert")
            ->assertStatus(500)
            ->assertJsonStructure(['message', 'reference']);

        expect($application->fresh()->supervisor_mode)->toBe(SupervisorMode::Pm2);
    });

    it('cannot be triggered by someone who cannot manage applications', function () {
        Process::fake();

        $viewer = User::factory()->create();
        $token = $viewer->createToken('t')->plainTextToken;
        $application = apiApp(['supervisor_mode' => 'pm2', 'pm2_process_name' => 'legacy-api']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/applications/{$application->id}/supervisor/convert")
            ->assertForbidden();

        expect($application->fresh()->supervisor_mode)->toBe(SupervisorMode::Pm2);
        Process::assertNothingRan();
    });
});

it('tells the frontend which supervisor an application is on', function () {
    Process::fake();

    $application = apiApp(['supervisor_mode' => 'pm2', 'pm2_process_name' => 'legacy-api']);

    $this->withHeaders(asAdmin())
        ->getJson("/api/applications/{$application->id}")
        ->assertOk()
        ->assertJsonPath('application.supervisor_mode', 'pm2');
});
