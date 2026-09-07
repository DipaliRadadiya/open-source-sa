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

it('gives an OpenLiteSpeed site its own user, which is what runs its PHP there', function () {
    // This test used to assert `ownuser:www-data`, on the premise that "no
    // PHP-FPM means the site stays on the shared, server-wide pool running as
    // www-data". That premise is false for LSPHP and the vhost says so: the
    // OpenLiteSpeed template writes `extUser {{ $user }}` / `extGroup`, so the
    // site's PHP has always run as the site's own user there. There is no
    // shared pool on that stack to fall back to.
    //
    // The rule this file exists for is unchanged — owner is the site user,
    // group is whoever runs PHP. On OpenLiteSpeed those are the same account,
    // so handing the group to www-data let an account that runs nothing on
    // that stack read every secret file.
    config(['server.web_server_drivers.nginx.php_stack' => 'lsphp']);

    expect(configOwner())->toBe('ownuser:ownuser');

    // And still no pool: `isolated_at` records an FPM pool, which LSPHP never
    // has. That column staying null is exactly why three separate callers used
    // to conclude the site did not run as its own user. {@see RuntimeOwnership}
    expect($this->application->fresh()->isolated_at)->toBeNull();
});

it('keeps an isolated site entirely to its own user', function () {
    // PHP-FPM: the provisioner gives the site its own pool, running as the
    // site user, so nobody else needs to be let in at all.
    config(['server.web_server_drivers.nginx.php_stack' => 'fpm']);

    expect(configOwner())->toBe('ownuser:ownuser')
        ->and($this->application->fresh()->isolated_at)->not->toBeNull();
});
