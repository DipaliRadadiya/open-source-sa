<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Reading a site's own logs. Every read shells out, because these files live
 * either under /var/log (root-owned) or inside the site's directory (owned by
 * its system user) — the panel account can open neither.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'logowner', 'home_path' => '/home/logowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Logged Site',
        'slug' => 'logged-site',
        'domain' => 'logged.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $this->files = [
        '/var/log/nginx/logged-site.access.log' => "GET / 200\nGET /about 200\nGET /missing 404\n",
        '/var/log/nginx/logged-site.error.log' => "PHP Warning: something\n",
    ];
    $this->journal = "-- Logs begin --\nsv-app: listening on 3000\n";
});

function fakeLogs(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;
        $files = test()->files;

        if ($binary === 'test') {
            return Process::result(exitCode: array_key_exists($args[2] ?? '', $files) ? 0 : 1);
        }

        if ($binary === 'tail') {
            $path = $args[3] ?? '';

            return array_key_exists($path, $files)
                ? Process::result(output: $files[$path])
                : Process::result(errorOutput: 'No such file', exitCode: 1);
        }

        if ($binary === 'journalctl') {
            return Process::result(output: test()->journal);
        }

        return Process::result(exitCode: 0);
    });
}

function logUrl(string $suffix = ''): string
{
    return '/api/applications/'.test()->application->id.'/logs'.$suffix;
}

it('lists the sources this application has, with localized labels', function () {
    fakeLogs();

    $response = $this->actingAs($this->admin)->getJson(logUrl())->assertOk();
    $logs = collect($response->json('logs'));

    expect($logs->pluck('key')->all())->toBe(['access', 'error'])
        ->and($logs->firstWhere('key', 'error')['label'])->toBe('Error log')
        ->and($logs->firstWhere('key', 'access')['exists'])->toBeTrue();
});

it('offers the process output only for an application that runs one', function () {
    fakeLogs();

    // A PHP site has no process — a journal source for it would be a screen
    // about nothing.
    expect(collect($this->actingAs($this->admin)->getJson(logUrl())->json('logs'))->pluck('key'))
        ->not->toContain('application');

    $this->application->update(['start_command' => 'node server.js']);

    $logs = collect($this->actingAs($this->admin)->getJson(logUrl())->json('logs'));

    // For a Node site the web-server logs describe the proxy, so without this
    // the screen would be confidently useless when it matters most.
    expect($logs->pluck('key'))->toContain('application')
        ->and($logs->firstWhere('key', 'application')['kind'])->toBe('journal');
});

it('reads a log', function () {
    fakeLogs();

    $response = $this->actingAs($this->admin)->getJson(logUrl('/access'))->assertOk();

    expect($response->json('log.lines'))->toBe(['GET / 200', 'GET /about 200', 'GET /missing 404'])
        ->and($response->json('log.exists'))->toBeTrue()
        ->and($response->json('log.truncated'))->toBeFalse();
});

it('filters literally, not as a regex', function () {
    fakeLogs();

    expect($this->actingAs($this->admin)->getJson(logUrl('/access?grep=404'))->json('log.lines'))
        ->toBe(['GET /missing 404']);

    // A regex metacharacter is matched as itself. Treating the filter as a
    // pattern would hand a user-supplied regex to a large file, which is a
    // denial of service waiting to happen.
    expect($this->actingAs($this->admin)->getJson(logUrl('/access?grep=.*'))->json('log.lines'))
        ->toBe([]);
});

it('reads the process output from the journal', function () {
    $this->application->update(['start_command' => 'node server.js']);
    fakeLogs();

    expect($this->actingAs($this->admin)->getJson(logUrl('/application'))->json('log.lines'))
        ->toContain('sv-app: listening on 3000');
});

it('answers calmly when the file does not exist yet', function () {
    $this->files = [];
    fakeLogs();

    // A site nobody has visited has no access log. That is not an error.
    $response = $this->actingAs($this->admin)->getJson(logUrl('/access'))->assertOk();

    expect($response->json('log.exists'))->toBeFalse()
        ->and($response->json('log.lines'))->toBe([]);
});

it('refuses a source it does not recognise', function () {
    fakeLogs();

    // The client names a key; the path comes from the driver. Nothing here
    // can be pointed at a file of the caller's choosing.
    $this->actingAs($this->admin)->getJson(logUrl('/etc-passwd'))->assertNotFound();
    $this->actingAs($this->admin)->getJson(logUrl('/application'))->assertNotFound();
});

it('caps how much can be asked for', function () {
    fakeLogs();

    $this->actingAs($this->admin)->getJson(logUrl('/access?lines=999999'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

describe('permissions', function () {
    it('is not satisfied by the server-wide logs permission', function () {
        fakeLogs();
        $user = User::factory()->create();
        grantPermission($user, 'logs', view: true);

        // Server `logs` is auth.log and syslog; `app_log` is one site's own
        // traffic. Letting one stand in for the other is escalation.
        $this->actingAs($user)->getJson(logUrl())->assertForbidden();
    });

    it('allows a user granted app_log', function () {
        fakeLogs();
        $user = User::factory()->create();
        grantPermission($user, 'app_log', view: true);

        $this->actingAs($user)->getJson(logUrl())->assertOk();
        $this->actingAs($user)->getJson(logUrl('/access'))->assertOk();
    });

    it('denies an unauthenticated caller', function () {
        fakeLogs();

        $this->getJson(logUrl())->assertUnauthorized();
    });
});
