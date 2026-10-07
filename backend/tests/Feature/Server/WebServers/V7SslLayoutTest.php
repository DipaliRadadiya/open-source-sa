<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use Illuminate\Support\Facades\Process;

/*
| v8 follows v7's file layout (step B1). v7 writes a site as `{name}.conf` for
| plain HTTP and a second file for HTTPS — `{name}-le-ssl.conf` with a Let's
| Encrypt certificate, `{name}-ssl.conf` with any other. v8 wrote one file, so a
| server moved from v7 kept v7's HTTPS file beside it: two server blocks for
| the same name on 443.
*/

beforeEach(function () {
    $this->su = SystemUser::create(['username' => 'v7demo', 'home_path' => '/home/v7demo', 'shell' => '/bin/bash']);
    $this->site = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'wpsite', 'slug' => 'wpsite', 'domain' => 'wpsite.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.2',
    ]);
    $this->site->domains()->create(['domain' => 'wpsite.example.com', 'type' => DomainType::Primary]);

    $this->ran = new ArrayObject;
    Process::fake(function ($process) {
        $this->ran->append(($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command);

        return Process::result();
    });
});

function siteCertificate(Application $site, CertificateType $type = CertificateType::LetsEncrypt): Certificate
{
    return Certificate::create([
        'application_id' => $site->id, 'type' => $type, 'status' => CertificateStatus::Active,
        'domains' => ['wpsite.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/wpsite/fullchain.pem',
        'private_key_path' => '/etc/letsencrypt/live/wpsite/privkey.pem',
        'issued_at' => now(), 'expires_at' => now()->addDays(89),
    ]);
}

/** @return array<string, ?string> */
function siteFiles(Application $site, string $driver): array
{
    return app((string) config("server.web_server_drivers.{$driver}.driver"))->configFiles(
        $site->fresh(['domains', 'certificate', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($site),
    );
}

function availableDir(string $driver): string
{
    return rtrim((string) config("server.web_server_drivers.{$driver}.sites_available_dir"), '/');
}

it('keeps HTTPS in {name}-le-ssl.conf and plain HTTP in {name}.conf', function (string $driver, string $tls, string $plain) {
    siteCertificate($this->site);
    $dir = availableDir($driver);

    $files = siteFiles($this->site, $driver);

    expect(array_keys($files))->toBe(["{$dir}/wpsite.conf", "{$dir}/wpsite-le-ssl.conf", "{$dir}/wpsite-ssl.conf"])
        ->and($files["{$dir}/wpsite-ssl.conf"])->toBeNull()
        ->and($files["{$dir}/wpsite.conf"])->toContain($plain)->not->toContain($tls)
        ->and($files["{$dir}/wpsite-le-ssl.conf"])->toContain($tls)->not->toContain($plain)
        ->and($files["{$dir}/wpsite-le-ssl.conf"])->toContain('/etc/letsencrypt/live/wpsite/fullchain.pem');
})->with([
    'nginx' => ['nginx', 'listen 443 ssl', 'listen 80;'],
    'apache' => ['apache', '<VirtualHost *:443>', '<VirtualHost *:80>'],
]);

it('names the HTTPS file {name}-ssl.conf for a certificate that is not Let\'s Encrypt', function (string $driver) {
    siteCertificate($this->site, CertificateType::Custom);
    $dir = availableDir($driver);

    $files = siteFiles($this->site, $driver);

    expect($files["{$dir}/wpsite-ssl.conf"])->not->toBeNull()
        ->and($files["{$dir}/wpsite-le-ssl.conf"])->toBeNull();
})->with(['nginx', 'apache']);

it('has no HTTPS file without a certificate, and still owns 443 in the main one', function (string $driver, string $reject) {
    $dir = availableDir($driver);

    $files = siteFiles($this->site, $driver);

    expect($files["{$dir}/wpsite-le-ssl.conf"])->toBeNull()
        ->and($files["{$dir}/wpsite-ssl.conf"])->toBeNull()
        ->and($files["{$dir}/wpsite.conf"])->toContain($reject);
})->with([
    'nginx' => ['nginx', 'ssl_reject_handshake on;'],
    'apache' => ['apache', '<VirtualHost *:443>'],
]);

it('serves the site from the HTTPS file only, when HTTPS is forced', function () {
    siteCertificate($this->site)->forceFill(['force_https' => true])->save();
    $dir = availableDir('nginx');

    $files = siteFiles($this->site, 'nginx');

    // Plain HTTP only redirects (and answers the ACME challenge).
    expect($files["{$dir}/wpsite.conf"])->toContain('return 301 https://')->not->toContain('fastcgi_pass')
        ->and($files["{$dir}/wpsite-le-ssl.conf"])->toContain('fastcgi_pass');
});

it('declares the firewall log format once, in the file nginx loads first', function () {
    // nginx includes sites-enabled in name order and `-le-ssl` sorts before
    // `.conf`. Declared in both files is a duplicate; declared only in the
    // later one is "unknown log format" in the earlier.
    siteCertificate($this->site);
    $this->site->forceFill(['waf_enabled' => true])->save();
    $dir = availableDir('nginx');

    $files = siteFiles($this->site, 'nginx');

    expect(substr_count((string) $files["{$dir}/wpsite-le-ssl.conf"], 'log_format '))->toBe(1)
        ->and(substr_count((string) $files["{$dir}/wpsite.conf"], 'log_format '))->toBe(0)
        ->and(strcmp('wpsite-le-ssl.conf', 'wpsite.conf'))->toBeLessThan(0);
});

it('writes and links the HTTPS file, and removes the one under the other name', function () {
    siteCertificate($this->site);
    $driver = app((string) config('server.web_server_drivers.nginx.driver'));
    $dir = availableDir('nginx');
    $enabled = rtrim((string) config('server.web_server_drivers.nginx.sites_dir'), '/');

    $driver->apply($this->site->fresh(['domains', 'certificate', 'systemUser']), app(ApplicationProvisioner::class)->documentRoot($this->site));

    $lines = collect($this->ran)->map(fn ($c) => implode(' ', $c));

    expect($lines)->toContain("tee {$dir}/wpsite.conf")
        ->toContain("tee {$dir}/wpsite-le-ssl.conf")
        ->toContain("rm -f {$enabled}/wpsite-ssl.conf")
        ->toContain("rm -f {$dir}/wpsite-ssl.conf")
        ->and($lines->contains(fn ($l) => str_starts_with($l, 'ln ') && str_contains($l, "{$dir}/wpsite-le-ssl.conf") && str_contains($l, "{$enabled}/wpsite-le-ssl.conf")))->toBeTrue();
});

it('removes every file the site can have when it is deleted', function () {
    $driver = app((string) config('server.web_server_drivers.nginx.driver'));
    $dir = availableDir('nginx');
    $enabled = rtrim((string) config('server.web_server_drivers.nginx.sites_dir'), '/');

    $driver->remove($this->site);

    $lines = collect($this->ran)->map(fn ($c) => implode(' ', $c));

    foreach (['wpsite.conf', 'wpsite-le-ssl.conf', 'wpsite-ssl.conf'] as $file) {
        expect($lines)->toContain("rm -f {$dir}/{$file}")->toContain("rm -f {$enabled}/{$file}");
    }
});

it('leaves OpenLiteSpeed as one file per site, its layout is already v7\'s', function () {
    siteCertificate($this->site);

    expect(siteFiles($this->site, 'openlitespeed'))->toHaveCount(1);
});
