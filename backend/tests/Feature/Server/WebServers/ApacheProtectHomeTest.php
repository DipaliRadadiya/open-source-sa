<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\WebServers\ApacheDriver;
use Illuminate\Support\Facades\Process;

/**
 * Ubuntu 26.04's apache2.service ships `ProtectHome=read-only`. Every site logs
 * to `<home>/<site>/logs`, so the first site's reload failed with "Read-only
 * file system: could not open error log file" and Apache exited, taking the
 * panel down with it. Found on the 26.04 test server.
 */
beforeEach(function () {
    $this->protectHome = 'read-only';
    $this->configValid = true;
    $this->ran = new ArrayObject;

    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        test()->ran[] = $args;

        if ($args === ['systemctl', 'show', 'apache2', '-p', 'ProtectHome', '--value']) {
            return Process::result(output: test()->protectHome."\n");
        }

        // As apachectl answers (measured): "Syntax OK" on stderr and 0, or the
        // error on stderr and 1.
        if ($args === ['apachectl', 'configtest']) {
            return test()->configValid
                ? Process::result(errorOutput: "Syntax OK\n")
                : Process::result(errorOutput: "AH00526: Syntax error on line 3 of /etc/apache2/sites-enabled/shop.conf:\nInvalid command 'Bogus'\n", exitCode: 1);
        }

        if ($args === ['systemctl', 'restart', 'apache2']) {
            test()->protectHome = 'no';
        }

        return Process::result();
    });

    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner', 'shell' => '/bin/bash', 'sudo' => false]);

    $this->site = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);
});

function apacheRan(array $command): bool
{
    return collect(test()->ran)->contains($command);
}

it('lifts a read-only /home with a drop-in and restarts Apache before a site is served', function () {
    app(ApacheDriver::class)->ensureDirectories($this->site);

    $dropIn = '/etc/systemd/system/apache2.service.d/panel-site-logs.conf';
    $tee = collect($this->ran)->search(['tee', $dropIn]);

    expect($tee)->not->toBeFalse()
        ->and(apacheRan(['systemctl', 'daemon-reload']))->toBeTrue()
        ->and(apacheRan(['systemctl', 'restart', 'apache2']))->toBeTrue()
        // The config is tested before the restart, never after.
        ->and(collect($this->ran)->search(['apachectl', 'configtest']))->toBeLessThan(collect($this->ran)->search(['systemctl', 'restart', 'apache2']));

    Process::assertRan(fn ($p) => in_array('tee', $p->command, true) && $p->input === "[Service]\nProtectHome=no\n");
});

it('does not restart Apache onto a config it would refuse', function () {
    // A restart with a broken config stops Apache and leaves it stopped. The
    // drop-in is written either way; the restart waits for a config that passes.
    $this->configValid = false;
    $driver = app(ApacheDriver::class);

    $driver->ensureDirectories($this->site);

    expect(apacheRan(['apachectl', 'configtest']))->toBeTrue()
        ->and(apacheRan(['systemctl', 'restart', 'apache2']))->toBeFalse()
        ->and(collect($this->ran)->contains(fn ($a) => ($a[0] ?? '') === 'tee' && str_contains($a[1] ?? '', 'apache2.service.d')))->toBeTrue();

    // Fixed later: the next site retries, and now restarts.
    $this->configValid = true;
    $driver->ensureDirectories($this->site);

    expect(apacheRan(['systemctl', 'restart', 'apache2']))->toBeTrue();
});

it('does nothing on a unit that already lets Apache write under /home', function () {
    $this->protectHome = 'no';

    app(ApacheDriver::class)->ensureDirectories($this->site);

    expect(apacheRan(['systemctl', 'restart', 'apache2']))->toBeFalse()
        ->and(collect($this->ran)->contains(fn ($a) => ($a[0] ?? '') === 'tee' && str_contains($a[1] ?? '', 'apache2.service.d')))->toBeFalse();
});

it('does not restart Apache on a guess when the unit cannot be read', function () {
    Process::fake(fn () => Process::result(errorOutput: 'Failed to connect to bus', exitCode: 1));

    app(ApacheDriver::class)->ensureDirectories($this->site);

    Process::assertDidntRun(fn ($p) => in_array('restart', $p->command, true));
});

it('checks the unit once per run, not once per site', function () {
    $driver = app(ApacheDriver::class);
    $driver->ensureDirectories($this->site);
    $driver->ensureDirectories($this->site);

    expect(collect($this->ran)->filter(fn ($a) => ($a[1] ?? '') === 'show')->count())->toBe(1);
});

it('is written by the installer too, with the same setting', function () {
    $script = (string) file_get_contents(dirname(base_path()).'/install.sh');

    expect($script)->toContain("printf '[Service]\\nProtectHome=no\\n' >/etc/systemd/system/apache2.service.d/\${PANEL_SLUG}-site-logs.conf");
});
