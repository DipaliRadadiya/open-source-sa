<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A marketplace installer writes a file holding database credentials and then
 * runs the application's own CLI against it. Two accounts must be able to read it:
 *
 *  - the site user, because that is who the CLI runs as — always;
 *  - the web server's account, but only where PHP runs under the shared,
 *    server-wide pool rather than the site's own.
 *
 * Owning it to one of them locks out the other, so this pins: **owner is the
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

/** The chown applied to the site's own config file, as `owner:group`. */
function configOwner(): string
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->provision(test()->application);

    // A root chown only runs when the group differs from the site user (PHP as www-data).
    // Otherwise the user wrote the file (`runuser -u <user> -- tee …`): a root chown would
    // follow a symlink planted at the path.
    $chown = collect($runs)->first(fn ($command) => ($command[0] ?? '') === 'chown'
        && str_ends_with((string) end($command), '/config.inc.php'));

    if ($chown !== null) {
        return (string) $chown[count($chown) - 2];
    }

    $write = collect($runs)->first(fn ($command) => ($command[0] ?? '') === 'runuser'
        && ($command[4] ?? '') === 'tee'
        && str_ends_with((string) end($command), '/config.inc.php'));

    return $write === null ? '' : "{$write[2]}:{$write[2]}";
}

it('gives an OpenLiteSpeed site its own user, which is what runs its PHP there', function () {
    // LSPHP has no shared pool: the OpenLiteSpeed template writes `extUser {{ $user }}` /
    // `extGroup`, so PHP runs as the site's own user. Owner and group are therefore the
    // same account; giving the group to www-data would let an account that runs nothing
    // on that stack read every secret file.
    config(['server.web_server_drivers.nginx.php_stack' => 'lsphp']);

    expect(configOwner())->toBe('ownuser:ownuser');

    // `isolated_at` records an FPM pool, which LSPHP never has, so it stays null.
    // {@see RuntimeOwnership}
    expect($this->application->fresh()->isolated_at)->toBeNull();
});

it('keeps an isolated site entirely to its own user', function () {
    // PHP-FPM: the site gets its own pool running as the site user, so nobody else needs access.
    config(['server.web_server_drivers.nginx.php_stack' => 'fpm']);

    expect(configOwner())->toBe('ownuser:ownuser')
        ->and($this->application->fresh()->isolated_at)->not->toBeNull();
});
