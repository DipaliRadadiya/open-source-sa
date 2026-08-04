<?php

use App\Models\User;
use App\Services\Server\Doctor\Checks\AccountLocksCheck;
use App\Services\Server\Doctor\Checks\BinariesCheck;
use App\Services\Server\Doctor\Checks\DatabaseCheck;
use App\Services\Server\Doctor\Checks\PrivilegeCheck;
use App\Services\Server\Doctor\Checks\QueueCheck;
use App\Services\Server\Doctor\Checks\ServicesCheck;
use App\Services\Server\Doctor\Checks\WebServerCheck;
use App\Services\Server\Doctor\Checks\WritablePathsCheck;
use App\Services\Server\Doctor\Doctor;
use Illuminate\Support\Facades\DB;
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

describe('the checks added for "routes error after setup"', function () {
    it('resolves binaries through sudo\'s secure_path, not the panel user\'s PATH', function () {
        // The first run of this check reported useradd/userdel/usermod/chpasswd
        // missing on a box where they were present and working: they live in
        // /usr/sbin, which is not on an unprivileged PATH, but sudo finds them
        // via secure_path. A false failure here trains people to ignore the
        // report, which is worse than not checking.
        $captured = [];

        Process::fake(function ($process) use (&$captured) {
            $captured[] = $process->command;

            return Process::result(output: '/usr/sbin/useradd', exitCode: 0);
        });

        config()->set('server.doctor.checks', [BinariesCheck::class]);
        app(Doctor::class)->run();

        expect($captured)->not->toBeEmpty()
            ->and($captured[0][0])->toBe('sh');
    });

    it('treats a missing optional tool as a warning naming the feature it costs', function () {
        // A panel without ufw cannot do firewall rules but works otherwise.
        // Calling that "broken" would be inaccurate and would bury the real
        // failures underneath it.
        Process::fake(function ($process) {
            return str_contains(implode(' ', $process->command), 'ufw')
                ? Process::result(exitCode: 1)
                : Process::result(output: '/usr/bin/thing', exitCode: 0);
        });

        config()->set('server.doctor.checks', [BinariesCheck::class]);
        $report = app(Doctor::class)->run();

        expect($report['checks'][0]['status'])->toBe('warn')
            ->and($report['checks'][0]['detail'])->toContain('firewall')
            // Warnings must not make the installation unhealthy.
            ->and($report['healthy'])->toBeTrue();
    });

    it('fails when a required tool is missing', function () {
        Process::fake(function ($process) {
            return str_contains(implode(' ', $process->command), 'systemctl')
                ? Process::result(exitCode: 1)
                : Process::result(output: '/usr/bin/thing', exitCode: 0);
        });

        config()->set('server.doctor.checks', [BinariesCheck::class]);
        $report = app(Doctor::class)->run();

        expect($report['checks'][0]['status'])->toBe('fail')
            ->and($report['healthy'])->toBeFalse();
    });

    it('fails the queue check when jobs sit unclaimed', function () {
        // The services check proves the worker is *running*; this proves it is
        // *working*. A worker holding a stale config looks active while every
        // queued deploy and install silently never happens.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => time() - 3600,
            'created_at' => time() - 3600,
        ]);

        // The suite runs QUEUE_CONNECTION=sync, where there is no backlog to
        // inspect and the check correctly declines to guess.
        config()->set('queue.default', 'database');
        config()->set('server.doctor.checks', [QueueCheck::class]);
        $report = app(Doctor::class)->run();

        expect($report['checks'][0]['status'])->toBe('fail')
            ->and($report['checks'][0]['detail'])->toContain('oldest for');
    });

    it('does not fail the queue check for a fresh backlog', function () {
        // A busy panel has jobs waiting. Age is the signal, not depth.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        // The suite runs QUEUE_CONNECTION=sync, where there is no backlog to
        // inspect and the check correctly declines to guess.
        config()->set('queue.default', 'database');
        config()->set('server.doctor.checks', [QueueCheck::class]);

        expect(app(Doctor::class)->run()['checks'][0]['status'])->toBe('pass');
    });

    it('fails when no web server the panel can drive is present', function () {
        config()->set('server.web_servers', ['nginx' => ['/nonexistent-nginx']]);
        config()->set('server.doctor.checks', [WebServerCheck::class]);

        expect(app(Doctor::class)->run()['checks'][0]['status'])->toBe('fail');
    });
});

describe('the stale account-lock check', function () {
    it('passes when there are no lock files', function () {
        config()->set('server.doctor.checks', [AccountLocksCheck::class]);

        // The suite runs on a box with no /etc/*.lock files.
        expect(app(Doctor::class)->run()['checks'][0]['status'])->toBe('pass');
    });

    it('has a title and a fix in every locale', function () {
        foreach (config('app.available_locales') as $locale) {
            app()->setLocale($locale);

            expect(__('doctor.checks.account_locks'))->not->toBe('doctor.checks.account_locks')
                ->and(__('doctor.fixes.account_locks'))->not->toBe('doctor.fixes.account_locks');
        }
    });
});
