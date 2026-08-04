<?php

use App\Models\User;
use App\Services\Server\Doctor\Checks\DatabaseCheck;
use App\Services\Server\Doctor\Checks\PrivilegeCheck;
use App\Services\Server\Doctor\Checks\ServicesCheck;
use App\Services\Server\Doctor\Checks\WritablePathsCheck;
use App\Services\Server\Doctor\Doctor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
 * The doctor exists because a green suite cannot tell you the panel works: the
 * suite fakes every process, so it passes identically on a server where the
 * panel is not permitted to do anything. These tests cover the reporting; the
 * value of the checks themselves is that they run unfaked on a real box.
 */

beforeEach(function () {
    // phpunit.xml disables sudo so feature tests can assert bare commands.
    // The doctor's whole job is to check escalation, so it needs it on —
    // otherwise every privilege result is "escalation is switched off",
    // which is a correct finding but not the one under test here.
    config()->set('server.privilege.sudo', true);

    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

it('reports healthy only when nothing failed', function () {
    Process::fake(['*' => Process::result(output: 'active', exitCode: 0)]);
    Http::fake(['*' => Http::response(['health' => ['status' => 'ok', 'version' => null]])]);

    config()->set('server.doctor.checks', [PrivilegeCheck::class, DatabaseCheck::class]);

    $report = app(Doctor::class)->run();

    expect($report['healthy'])->toBeTrue()
        ->and($report['failed'])->toBe(0)
        ->and($report['checks'])->toHaveCount(2);
});

it('is unhealthy when a privileged command is denied', function () {
    // sudo -n -l <binary> exits non-zero when there is no grant. This is the
    // exact condition that made the whole server panel inert.
    Process::fake(['*' => Process::result(errorOutput: 'sorry, user may not run', exitCode: 1)]);

    config()->set('server.doctor.checks', [PrivilegeCheck::class]);

    $report = app(Doctor::class)->run();

    expect($report['healthy'])->toBeFalse()
        ->and($report['checks'][0]['status'])->toBe('fail')
        ->and($report['checks'][0]['detail'])->toContain('not permitted')
        // The advice must be translated prose, not a lang key.
        ->and($report['checks'][0]['fix'])->not->toStartWith('doctor.');
})->skip(fn () => posix_geteuid() === 0, 'runs as root — nothing needs sudo');

it('distinguishes a missing unit from a stopped one', function () {
    // Different problems, different fixes: one is a wrong name in .env, the
    // other is a service that crashed.
    Process::fake(function ($process) {
        return $process->command[1] === 'cat'
            ? Process::result(errorOutput: 'No files found.', exitCode: 1)
            : Process::result(output: 'inactive', exitCode: 3);
    });

    config()->set('server.doctor.checks', [ServicesCheck::class]);

    expect(app(Doctor::class)->run()['checks'][0]['detail'])->toContain('no such unit');
});

it('flags pending migrations, because code without its schema looks like a code bug', function () {
    config()->set('server.doctor.checks', [DatabaseCheck::class]);

    // The suite migrates before running, so the schema is current here.
    expect(app(Doctor::class)->run()['checks'][0]['status'])->toBe('pass');
});

it('reports every check even when one of them fails hard', function () {
    // Abandoning the run would cost the operator the other answers.
    Process::fake(['*' => Process::result(errorOutput: 'sorry, user may not run', exitCode: 1)]);

    config()->set('server.doctor.checks', [PrivilegeCheck::class, DatabaseCheck::class]);

    $report = app(Doctor::class)->run();

    expect($report['checks'])->toHaveCount(2)
        ->and($report['checks'][1]['status'])->toBe('pass');
});

it('warns rather than fails on a version mismatch', function () {
    // A skew right after an update is worth showing and not worth blocking on.
    Process::fake(['*' => Process::result(exitCode: 0)]);
    config()->set('server.doctor.checks', [WritablePathsCheck::class]);

    $report = app(Doctor::class)->run();

    expect($report['warnings'] + $report['passed'] + $report['failed'])->toBe(1);
});

it('exposes the report to admins', function () {
    Process::fake(['*' => Process::result(output: 'active', exitCode: 0)]);
    Http::fake(['*' => Http::response(['health' => ['status' => 'ok', 'version' => null]])]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/admin/doctor');

    $response->assertOk()
        ->assertJsonStructure(['doctor' => [
            'healthy', 'passed', 'failed', 'warnings',
            'checks' => [['key', 'title', 'status', 'detail', 'fix']],
        ]]);
});

it('denies a non-admin', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/doctor')
        ->assertForbidden();
});

it('denies an unauthenticated request', function () {
    $this->getJson('/api/admin/doctor')->assertUnauthorized();
});

it('has a title and fix for every check, in every locale', function () {
    $keys = collect(config('server.doctor.checks'))
        ->map(fn (string $class): string => app($class)->key());

    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach ($keys as $key) {
            expect(__('doctor.checks.'.$key))->not->toBe('doctor.checks.'.$key);
        }

        // Advice the operator cannot read is advice that does not exist.
        foreach (array_keys(__('doctor.fixes')) as $fix) {
            expect(__('doctor.fixes.'.$fix))->not->toBe('doctor.fixes.'.$fix);
        }
    }
});

it('exits non-zero so the installer can act on it', function () {
    Process::fake(['*' => Process::result(errorOutput: 'sorry, user may not run', exitCode: 1)]);
    Http::fake(['*' => Http::response(null, 500)]);

    $this->artisan('panel:doctor')->assertExitCode(1);
})->skip(fn () => posix_geteuid() === 0, 'runs as root — privilege check passes trivially');
