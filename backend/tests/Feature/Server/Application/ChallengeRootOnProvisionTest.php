<?php

use App\Contracts\WebServerDriver;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/**
 * A vhost may not name a directory that does not exist yet.
 *
 * Every template — nginx, Apache and all three OpenLiteSpeed ones — declares a
 * context for `{challenge_root}/.well-known/acme-challenge`, and the only thing
 * that created that directory was `IssueCertificate`. So on a server where no
 * certificate had ever been requested, every site's configuration pointed at a
 * path that was not there.
 *
 * On nginx and Apache a location is resolved per request, so this stayed
 * invisible until issuance failed. **OpenLiteSpeed resolves a context's
 * location when the configuration is loaded**, and install.sh already hit this
 * for the panel's own vhost — its `ensure_ols_context_paths()` exists for this
 * exact reason. Sites were left out of that lesson.
 */
beforeEach(function () {
    // OpenLiteSpeed's driver reads the shared config back before registering a
    // site, so a blank `cat` would fail on a missing listener before reaching
    // what this file is about.
    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'cat'
        ? Process::result(output: "serverName x\n\nlistener Default {\n  address *:80\n}\n")
        : Process::result(output: ''));

    $this->su = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    config(['server.certificates.challenge_root' => '/var/www/.well-known-acme']);
});

function challengeSite(): Application
{
    $application = Application::forceCreate([
        'system_user_id' => test()->su->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'web_root' => '/', 'status' => 'pending',
    ]);

    return $application->load('systemUser');
}

/**
 * @param  'nginx'|'apache'|'openlitespeed'  $webServer
 */
function driverFor(string $webServer, string $stack): WebServerDriver
{
    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => $stack, 'web_server' => $webServer,
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    return app(WebServerManager::class)->driver();
}

it('creates the ACME challenge directory before writing a vhost that names it', function (string $webServer, string $stack) {
    $application = challengeSite();

    driverFor($webServer, $stack)->apply($application, $application->documentRoot());

    // `install -d`, the same command certbot's own preparation uses — one
    // definition of what that directory is, rather than a second `mkdir` here
    // that could drift from it in mode or path.
    Process::assertRan(fn ($process): bool => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        '/var/www/.well-known-acme/.well-known/acme-challenge',
    ));
})->with([
    ['nginx', 'lemp'],
    ['apache', 'lamp'],
    // The one where it is not merely untidy: OLS reads the location at
    // config-load time, so a missing directory reaches the config test.
    ['openlitespeed', 'ols'],
]);
