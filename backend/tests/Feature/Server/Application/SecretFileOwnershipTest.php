<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A marketplace installer writes a file holding database credentials and then
 * immediately runs the application's own CLI against it. Two different accounts
 * have to be able to read that file, and which two depends on the stack:
 *
 *  - the site user, because that is who the CLI runs as — always;
 *  - the web server's account, but only where PHP runs under the shared,
 *    server-wide pool rather than the site's own.
 *
 * Owning it to one of them locks out the other, and both halves of that have
 * been shipped. This pins the arrangement that satisfies both: **owner is the
 * site user, group is whoever runs PHP.**
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-own-'.getmypid();
    config([
        'server.installer_work_dir' => $this->home,
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.web_server_user' => 'www-data',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'ownuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Database admin',
        'slug' => 'database-admin',
        'domain' => 'db.example.com',
        'site_type' => 'phpmyadmin',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
    ]);
});

/**
 * The chown applied to the site's own config file, as `owner:group`.
 */
function configOwner(): string
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    $chown = collect($runs)->first(fn ($command) => ($command[0] ?? '') === 'chown'
        && str_ends_with((string) ($command[2] ?? ''), '/config.inc.php'));

    return (string) $chown[1];
}

it('gives a shared-pool site a group the web server can read through', function () {
    // No PHP-FPM, so `ApplicationProvisioner` creates no pool and the site
    // stays on the shared, server-wide one running as www-data.
    config(['server.web_server_drivers.nginx.php_stack' => 'lsphp']);

    expect(configOwner())->toBe('ownuser:www-data');

    // The site user still owns it — this is the half that was missing. The
    // installer's next command runs as ownuser and reads this file; owned by
    // www-data at 0640 it could not, which is why every config-file install
    // (WordPress, Moodle, Mautic, Craft, phpMyAdmin) failed on OpenLiteSpeed
    // and only there.
    expect($this->application->fresh()->isolated_at)->toBeNull();
});

it('keeps an isolated site entirely to its own user', function () {
    // PHP-FPM: the provisioner gives the site its own pool, running as the
    // site user, so nobody else needs to be let in at all.
    config(['server.web_server_drivers.nginx.php_stack' => 'fpm']);

    expect(configOwner())->toBe('ownuser:ownuser')
        ->and($this->application->fresh()->isolated_at)->not->toBeNull();
});
