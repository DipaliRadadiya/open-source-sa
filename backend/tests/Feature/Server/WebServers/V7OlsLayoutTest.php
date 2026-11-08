<?php

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\WebServers\OlsDriver;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/*
 * A v7 OpenLiteSpeed server: `httpd_config.conf` loads `/etc/<brand>-ols/*.conf`,
 * each a `virtualhost` wrapper that includes `<name>/main.conf`, on v7's own
 * `Default` and `DefaultHttps` listeners. v8 used to write main.conf AND its
 * own `virtualHost` + map regions on a second :443 listener, so the site was
 * defined twice (seen on a real v7 server, 2026-11-08). Now the wrapper is
 * written the v7 way and the shared regions are left alone.
 */

beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'ols', 'web_server' => 'openlitespeed', 'capabilities' => ['php' => true],
        'source' => 'installer', 'verified_at' => now(), 'ols_vhost_root' => '/etc/serveravatar-ols',
    ]);
    app()->forgetInstance(WebServerManager::class);

    $user = SystemUser::create(['username' => 'v7demo', 'home_path' => '/home/v7demo']);
    $this->site = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'wpblog', 'slug' => 'wpblog', 'domain' => 'wpblog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        'php_version' => '8.2', 'isolated_at' => now(),
    ]);
    $this->site->domains()->create(['domain' => 'wpblog.example.com', 'type' => DomainType::Primary]);
    $this->site = $this->site->fresh(['domains', 'certificate', 'systemUser']);
});

/**
 * A v7 box as `find` and `cat` see it: the v7 directory holds a vhost and the
 * panel has registered no site of its own.
 *
 * @param  list<string>  $ran
 */
function fakeV7OlsBox(array &$ran): void
{
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = $args;

        if (($args[0] ?? '') === 'find' && in_array('*-ols', $args, true)) {
            return Process::result(output: "/etc/serveravatar-ols\n");
        }

        if (($args[0] ?? '') === 'find') {
            return ($args[1] ?? '') === '/etc/serveravatar-ols'
                ? Process::result(output: "/etc/serveravatar-ols/wpblog/main.conf\n")
                : Process::result(output: '');
        }

        if (($args[0] ?? '') === 'cat') {
            return Process::result(output: "listener Default {\n}\nlistener DefaultHttps {\n}\ninclude /etc/serveravatar-ols/*.conf\n");
        }

        return Process::result(exitCode: ($args[0] ?? '') === 'test' ? 1 : 0);
    });
}

it('writes the site the v7 way: wrapper plus main.conf, on v7\'s listeners', function () {
    $ran = [];
    fakeV7OlsBox($ran);

    $files = app(OlsDriver::class)->configFiles($this->site, '/home/v7demo/wpblog/public_html');

    expect(array_keys($files))->toBe(['/etc/serveravatar-ols/wpblog/main.conf', '/etc/serveravatar-ols/wpblog.conf']);

    $wrapper = $files['/etc/serveravatar-ols/wpblog.conf'];
    expect($wrapper)->toContain('virtualhost wpblog {')
        ->toContain('listeners               Default,DefaultHttps')
        ->toContain('vhRoot                  /home/v7demo/wpblog/')
        ->toContain('include /etc/serveravatar-ols/wpblog/main.conf')
        // Defined once: the domains and certificate are in main.conf only.
        ->not->toContain('vhDomain')
        ->not->toContain('vhssl');

    expect($files['/etc/serveravatar-ols/wpblog/main.conf'])->toContain('vhDomain                  wpblog.example.com');
});

it('never writes the panel\'s own virtualHost or map for a v7-layout site', function () {
    $ran = [];
    fakeV7OlsBox($ran);

    $result = app(OlsDriver::class)->apply($this->site, '/home/v7demo/wpblog/public_html');

    $tees = collect($ran)->filter(fn (array $a) => ($a[0] ?? '') === 'tee')->map(fn (array $a) => $a[1] ?? '')->values()->all();

    expect($result->ok)->toBeTrue()
        ->and($tees)->toContain('/etc/serveravatar-ols/wpblog/main.conf')
        ->and($tees)->toContain('/etc/serveravatar-ols/wpblog.conf')
        // main.conf first: the wrapper includes it.
        ->and(array_search('/etc/serveravatar-ols/wpblog/main.conf', $tees))->toBeLessThan(array_search('/etc/serveravatar-ols/wpblog.conf', $tees))
        ->and($tees)->not->toContain('/usr/local/lsws/conf/httpd_config.conf');
});

it('removes the wrapper before the directory it includes', function () {
    $ran = [];
    fakeV7OlsBox($ran);

    app(OlsDriver::class)->remove($this->site);

    $flat = collect($ran)->map(fn (array $a) => implode(' ', $a))->values()->all();
    $wrapper = collect($flat)->search(fn ($c) => str_contains($c, '/etc/serveravatar-ols/wpblog.conf') && str_starts_with($c, 'rm'));
    $dir = collect($flat)->search(fn ($c) => $c === 'rm -rf /etc/serveravatar-ols/wpblog');

    expect($wrapper)->not->toBeFalse()
        ->and($dir)->not->toBeFalse()
        ->and($wrapper)->toBeLessThan($dir);
});

it('leaves a server with this panel\'s own layout as it was', function () {
    ServerCapability::query()->update(['ols_vhost_root' => null]);
    Process::fake(fn () => Process::result(output: ''));

    $files = app(OlsDriver::class)->configFiles($this->site->fresh(['domains', 'certificate', 'systemUser']), '/home/v7demo/wpblog/public_html');

    expect(array_keys($files))->toBe(['/usr/local/lsws/conf/vhosts/wpblog/vhconf.conf']);
});
