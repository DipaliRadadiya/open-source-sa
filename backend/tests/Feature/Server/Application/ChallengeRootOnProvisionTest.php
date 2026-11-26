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
 * Every template (nginx, Apache, OpenLiteSpeed) declares a context for
 * `{challenge_root}/.well-known/acme-challenge`, so the directory must exist before the vhost
 * is written, not only once `IssueCertificate` runs.
 *
 * nginx and Apache resolve a location per request; **OpenLiteSpeed resolves a context's
 * location when the configuration is loaded**, so a missing directory breaks the config test.
 */
beforeEach(function () {
    // OpenLiteSpeed's driver reads the shared config back before registering a site; a blank
    // `cat` would fail on a missing listener first.
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

    // `install -d`, the same command certbot's preparation uses, so mode and path cannot drift.
    Process::assertRan(fn ($process): bool => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        '/var/www/.well-known-acme/.well-known/acme-challenge',
    ));
})->with([
    ['nginx', 'lemp'],
    ['apache', 'lamp'],
    // OLS reads the location at config-load time, so a missing directory fails the config test.
    ['openlitespeed', 'ols'],
]);
