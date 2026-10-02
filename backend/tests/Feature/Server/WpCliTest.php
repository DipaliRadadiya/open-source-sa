<?php

use App\Jobs\InstallWpCli;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Bug #4: the health check said wp-cli was missing and to "install it from
 * the setup page", and the setup page had no way to. v7 has the step.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

/**
 * A box where wp-cli is present or not, and where the download either works
 * or fails with the given curl error. A working download puts it in place.
 */
function fakeWpCliBinary(bool $present, ?string $curlError = null): ArrayObject
{
    $state = new ArrayObject(['present' => $present, 'runs' => []]);

    Process::fake(function ($process) use ($state, $curlError) {
        $command = (array) $process->command;
        if (($command[0] ?? null) === 'sudo' && ($command[1] ?? null) === '-n') {
            $command = array_slice($command, 2);
        }
        $state['runs'] = [...$state['runs'], $command];

        return match ($command[0] ?? null) {
            'test' => Process::result(exitCode: $state['present'] ? 0 : 1),
            'curl' => $curlError !== null
                ? Process::result(errorOutput: $curlError, exitCode: 6)
                : (function () use ($state) {
                    $state['present'] = true;

                    return Process::result();
                })(),
            default => Process::result(),
        };
    });

    return $state;
}

function wpCliSetupRow(string $key): ?array
{
    return collect(test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/setup')->assertOk()->json('setup.components'))->firstWhere('key', $key);
}

it('offers WP-CLI on the setup page while it is missing, with the endpoint that installs it', function () {
    fakeWpCliBinary(present: false);

    $row = wpCliSetupRow('wp_cli');

    expect($row)->not->toBeNull()
        ->and($row['state'])->toBe('pending')
        ->and($row['title'])->toBe('WP-CLI')
        ->and($row['action'])->toBe(['method' => 'POST', 'endpoint' => '/api/wp-cli/install'])
        // Not part of the recommended set: adding it there would turn every
        // existing server's finished setup into an unfinished one.
        ->and($row['recommended'])->toBeFalse();
});

it('shows it installed once the binary is on the box', function () {
    fakeWpCliBinary(present: true);

    expect(wpCliSetupRow('wp_cli')['state'])->toBe('installed');
});

it('queues the install and records it before returning', function () {
    Queue::fake();
    fakeWpCliBinary(present: false);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/wp-cli/install')
        ->assertStatus(202);

    Queue::assertPushed(InstallWpCli::class);
    expect(app(InstallTracker::class)->current(InstallWpCli::RUNTIME, InstallWpCli::VERSION))->not->toBeNull()
        ->and(wpCliSetupRow('wp_cli')['state'])->toBe('installing');
});

it('refuses to install what is already there', function () {
    Queue::fake();
    fakeWpCliBinary(present: true);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/wp-cli/install')
        ->assertStatus(422)
        ->assertJsonPath('message', __('errors/wp-cli.already_installed'));

    Queue::assertNothingPushed();
});

it('downloads the same file the WordPress installer does, to the same place, over https only', function () {
    $state = fakeWpCliBinary(present: false);

    dispatch_sync(new InstallWpCli($this->admin->id));

    $curl = collect($state['runs'])->first(fn ($c) => $c[0] === 'curl');

    expect($curl)->toContain(config('server.installers.wordpress.wp_cli_url'), '/usr/local/bin/wp', '=https', '--fail')
        ->and(collect($state['runs'])->contains(fn ($c) => $c === ['chmod', '0755', '/usr/local/bin/wp']))->toBeTrue()
        ->and(app(InstallTracker::class)->current(InstallWpCli::RUNTIME, InstallWpCli::VERSION))->toBeNull()
        ->and(ActivityLog::where(['type' => 'wp_cli', 'action' => 'installed'])->exists())->toBeTrue();
});

it('says why a download failed, in words about GitHub rather than apt', function () {
    fakeWpCliBinary(present: false, curlError: 'curl: (6) Could not resolve host: raw.githubusercontent.com');
    app(InstallTracker::class)->start(InstallWpCli::RUNTIME, InstallWpCli::VERSION);

    dispatch_sync(new InstallWpCli($this->admin->id));

    $row = wpCliSetupRow('wp_cli');

    expect($row['state'])->toBe('failed')
        ->and($row['reason'])->toBe('network')
        ->and($row['message'])->toBe(__('runtime.wp_cli_install_failed.network'))
        ->and($row['retryable'])->toBeTrue();
});

it('is a unique job, so two clicks cannot write the file at once', function () {
    expect(new InstallWpCli)->toBeInstanceOf(ShouldBeUnique::class);
});

it('refuses installing with only view access to applications', function () {
    Queue::fake();
    fakeWpCliBinary(present: false);

    $viewer = User::factory()->create();
    grantPermission($viewer, 'application');

    $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->postJson('/api/wp-cli/install')->assertForbidden();

    Queue::assertNothingPushed();
});

it('lets a non-admin who may manage applications install it', function () {
    Queue::fake();
    fakeWpCliBinary(present: false);

    $manager = User::factory()->create();
    grantPermission($manager, 'application', manage: true);

    $this->withHeader('Authorization', 'Bearer '.$manager->createToken('t')->plainTextToken)
        ->postJson('/api/wp-cli/install')->assertStatus(202);

    Queue::assertPushed(InstallWpCli::class);
});
