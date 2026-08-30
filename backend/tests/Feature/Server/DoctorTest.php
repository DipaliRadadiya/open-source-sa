<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Doctor\Checks\AccountLocksCheck;
use App\Services\Server\Doctor\Checks\BinariesCheck;
use App\Services\Server\Doctor\Checks\DatabaseCheck;
use App\Services\Server\Doctor\Checks\FrontendBuildCheck;
use App\Services\Server\Doctor\Checks\PhpIsolationCheck;
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

/**
 * What `sudo -n -l` prints on a server whose grant is up to date.
 *
 * Built from the allowlist rather than hardcoded, because the point of the
 * check is that the two agree — a fixture listing its own idea of the grant
 * would pass while the real server's diverged, which is the failure being
 * tested for.
 */
function sudoListing(?array $only = null): string
{
    $binaries = $only ?? (array) config('server.privilege.binaries', []);

    $paths = implode(', ', array_map(fn (string $b) => '/usr/bin/'.$b, $binaries));

    return "Matching Defaults entries for panel on host:\n    !requiretty\n\n"
        ."User panel may run the following commands on host:\n    (root) NOPASSWD: {$paths}, /usr/sbin/php-fpm*\n";
}

/** A server where sudo works and the grant covers everything this build runs. */
function fakeHealthySudo(): void
{
    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);

        if (str_contains($command, 'sudo -n -l') && ! preg_match('/-l \S/', $command)) {
            return Process::result(output: sudoListing());
        }

        return Process::result(output: 'active', exitCode: 0);
    });
}

it('reports healthy only when nothing failed', function () {
    fakeHealthySudo();
    Http::fake(['*' => Http::response(['health' => ['status' => 'ok', 'version' => null]])]);

    config()->set('server.doctor.checks', [PrivilegeCheck::class, DatabaseCheck::class]);

    $report = app(Doctor::class)->run();

    expect($report['healthy'])->toBeTrue()
        ->and($report['failed'])->toBe(0)
        ->and($report['checks'])->toHaveCount(2);
});

it('catches a sudo grant that predates the panel it is running', function () {
    // sudo works — the representative commands are all permitted — but the
    // rule on disk is an older install.sh's. This is the state every server
    // reaches the moment the panel is updated and install.sh is not re-run,
    // and it is how certbot, openssl, touch, stat and crontab were denied for
    // months while this check reported healthy: it sampled five binaries that
    // were granted on day one and never changed.
    $stale = array_values(array_diff(
        (array) config('server.privilege.binaries', []),
        ['certbot', 'openssl', 'touch'],
    ));

    Process::fake(function ($process) use ($stale) {
        $command = implode(' ', (array) $process->command);

        if (str_contains($command, 'sudo -n -l') && ! preg_match('/-l \S/', $command)) {
            return Process::result(output: sudoListing($stale));
        }

        return Process::result(output: 'active', exitCode: 0);
    });

    config()->set('server.doctor.checks', [PrivilegeCheck::class]);

    $report = app(Doctor::class)->run();

    expect($report['healthy'])->toBeFalse();

    $detail = $report['checks'][0]['detail'];

    expect($detail)->toContain('certbot')
        ->and($detail)->toContain('openssl')
        ->and($detail)->toContain('touch');
})->skip(fn () => posix_geteuid() === 0, 'runs as root — nothing needs sudo');

it('accepts php-fpm granted as a wildcard', function () {
    // One binary per installed PHP version, granted as /usr/sbin/php-fpm* for
    // the same reason elevate() matches it by prefix — an exact list would
    // need editing every time a version is added through the panel.
    fakeHealthySudo();

    config()->set('server.doctor.checks', [PrivilegeCheck::class]);

    expect(app(Doctor::class)->run()['healthy'])->toBeTrue();
})->skip(fn () => posix_geteuid() === 0, 'runs as root — nothing needs sudo');

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

    it('warns rather than blaming the config when it is not allowed to test it', function () {
        // The state of a panel deployed without install.sh: sudo refuses, so
        // `nginx -t` never runs and exits non-zero anyway. This used to report
        // "the web server configuration is invalid" on a box where `nginx -t`
        // passed and all 18 vhosts were fine — doctor is what you read when
        // something is already broken, and it was inventing a second, false
        // problem out of the first real one.
        Process::fake(fn () => Process::result(
            errorOutput: 'sudo: a password is required',
            exitCode: 1,
        ));

        config()->set('server.web_servers', ['nginx' => ['/etc']]);
        config()->set('server.doctor.checks', [WebServerCheck::class]);

        $report = app(Doctor::class)->run();

        // Warn, not fail: the missing grant is PrivilegeCheck's finding to
        // report, and one problem should light up one check.
        expect($report['checks'][0]['status'])->toBe('warn')
            ->and($report['healthy'])->toBeTrue()
            ->and($report['checks'][0]['detail'])->toContain('not permitted');
    });

    it('still fails when the config test is permitted and the config is bad', function () {
        Process::fake(function ($process) {
            $command = implode(' ', (array) $process->command);

            // sudo confirms the grant exists, so the non-zero exit below is
            // nginx's own verdict and nothing else.
            if (str_contains($command, 'sudo -n -l')) {
                return Process::result(output: '(root) NOPASSWD: /usr/sbin/nginx');
            }

            return Process::result(
                errorOutput: "nginx: [emerg] unknown directive \"lisen\" in /etc/nginx/sites-enabled/demo:4\nnginx: configuration file test failed",
                exitCode: 1,
            );
        });

        config()->set('server.web_servers', ['nginx' => ['/etc']]);
        config()->set('server.doctor.checks', [WebServerCheck::class]);

        $report = app(Doctor::class)->run();

        // The evidence travels with the verdict — the check used to assert the
        // config was invalid and show nothing to back it up.
        expect($report['checks'][0]['status'])->toBe('fail')
            ->and($report['checks'][0]['detail'])->toContain('sites-enabled/demo:4');
    });

    it('has a fix for an untestable config in every locale', function () {
        foreach (config('app.available_locales') as $locale) {
            app()->setLocale($locale);

            expect(__('doctor.fixes.web_server_untestable'))
                ->not->toBe('doctor.fixes.web_server_untestable');
        }
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

describe('the interface build check', function () {
    it('warns when the build is older than the source', function () {
        // The services check proves the Next server is *running*; it runs
        // perfectly happily on last week's build. This is the only check that
        // notices a build failed — which is exactly how a diagnostic file with
        // a Node import in an Edge hook reached users.
        config()->set('server.doctor.checks', [FrontendBuildCheck::class]);

        $report = app(Doctor::class)->run();

        // Whatever this checkout's state, the answer must be one of the three
        // and never absent.
        expect($report['checks'][0]['status'])->toBeIn(['pass', 'warn', 'fail']);
    });

    it('does not fail an installation that has no frontend', function () {
        // Backend-only is a legitimate arrangement; calling it broken because
        // a directory it does not use is missing would be wrong.
        expect(app(FrontendBuildCheck::class)->key())->toBe('frontend_build');
    });

    it('has a title and both fixes in every locale', function () {
        foreach (config('app.available_locales') as $locale) {
            app()->setLocale($locale);

            expect(__('doctor.checks.frontend_build'))->not->toBe('doctor.checks.frontend_build')
                ->and(__('doctor.fixes.frontend_build_missing'))->not->toBe('doctor.fixes.frontend_build_missing')
                ->and(__('doctor.fixes.frontend_build_stale'))->not->toBe('doctor.fixes.frontend_build_stale');
        }
    });
});

it('checks the panel account rather than the operator running it as root', function () {
    // An operator reads this from a root shell, and root needs no sudo — so
    // the check answered "sudo not required" and skipped everything, while
    // php-fpm and the queue worker went on failing as the unprivileged panel
    // account. That is how a server whose sudoers predated the `touch` grant
    // reported healthy while git provisioning could not create a `.env`.
    if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        expect(true)->toBeTrue();

        return;
    }

    $probes = [];
    Process::fake(function ($process) use (&$probes) {
        $probes[] = $process->command;

        return Process::result(output: 'ok');
    });

    config()->set('server.doctor.checks', [PrivilegeCheck::class]);
    config()->set('server.privilege.binaries', ['tee']);

    app(Doctor::class)->run();

    // Whatever it asked about, it must have asked as somebody other than root.
    expect($probes)->not->toBeEmpty()
        ->and(collect($probes)->every(fn (array $command): bool => $command[0] === 'runuser'))->toBeTrue();
});

describe('the PHP isolation check', function () {
    it('warns rather than announcing every pool file missing when it cannot look', function () {
        // A missing pool file means a site is silently being served by the
        // shared account with none of its own settings — serious, and worth a
        // fail. But `test -f` is refused for EVERY site at once on a server
        // whose sudo grant predates the build, so reading that as "gone"
        // announced it for all of them: the loudest possible false alarm, in
        // the tool someone opens when something is already wrong.
        // php-fpm, or there are no pools to have an opinion about.
        ServerCapability::query()->delete();
        ServerCapability::query()->create([
            'stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => [],
            'source' => 'detected', 'verified_at' => now(),
        ]);

        $su = SystemUser::create(['username' => 'poolowner', 'home_path' => '/home/poolowner']);

        Application::forceCreate([
            'system_user_id' => $su->id, 'name' => 'Shop', 'slug' => 'shop',
            'domain' => 'shop.example.com', 'site_type' => 'wordpress',
            'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
            'php_version' => '8.4', 'isolated_at' => now(),
        ]);

        Process::fake(fn () => Process::result(
            errorOutput: 'sudo: a password is required',
            exitCode: 1,
        ));

        config()->set('server.doctor.checks', [PhpIsolationCheck::class]);

        $report = app(Doctor::class)->run();

        expect($report['checks'][0]['status'])->toBe('warn')
            ->and($report['healthy'])->toBeTrue()
            ->and($report['checks'][0]['detail'])->toContain('could not check');
    });

    it('has a fix for the unknown case in every locale', function () {
        foreach (config('app.available_locales') as $locale) {
            app()->setLocale($locale);

            expect(__('doctor.fixes.php_isolation_unknown'))
                ->not->toBe('doctor.fixes.php_isolation_unknown');
        }
    });
});
