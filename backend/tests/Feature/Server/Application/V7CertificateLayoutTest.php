<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;

/*
| v8 follows v7's file layout (step B3). v7 names a certificate's certbot
| lineage after the site and renews it through the webroot /var/www/html. v8
| named it after the first domain and served the ACME challenge from another
| directory, so once v8 wrote a v7 site's vhost its certificate could not be
| removed by name and stopped renewing.
*/

beforeEach(function () {
    $this->su = SystemUser::create(['username' => 'v7demo', 'home_path' => '/home/v7demo', 'shell' => '/bin/bash']);
    $this->site = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'wpsite', 'slug' => 'wpsite', 'domain' => 'wpsite.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.2',
    ]);
    $this->site->domains()->create(['domain' => 'wpsite.example.com', 'type' => DomainType::Primary]);
});

function v7Certificate(Application $site, array $overrides = []): Certificate
{
    return Certificate::create(array_merge([
        'application_id' => $site->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
        'domains' => ['wpsite.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/wpsite/fullchain.pem',
        'private_key_path' => '/etc/letsencrypt/live/wpsite/privkey.pem',
        'issued_at' => now(), 'expires_at' => now()->addDays(60),
    ], $overrides));
}

it('reads the lineage from where the certificate files are', function (?string $path, ?string $expected) {
    expect(v7Certificate($this->site, ['certificate_path' => $path])->lineageName())->toBe($expected);
})->with([
    'v7 and v8 now: the site name' => ['/etc/letsencrypt/live/wpsite/fullchain.pem', 'wpsite'],
    'issued under the old v8 rule: the domain' => ['/etc/letsencrypt/live/wpsite.example.com/fullchain.pem', 'wpsite.example.com'],
    'no files recorded: the name it would have' => [null, 'wpsite'],
]);

it('has no lineage for a certificate certbot does not manage', function () {
    expect(v7Certificate($this->site, ['type' => CertificateType::Custom])->lineageName())->toBeNull();
});

it('serves the ACME challenge from v7\'s webroot, so v7 certificates keep renewing', function () {
    // A v7 renewal file says `webroot_path = /var/www/html`; certbot writes the
    // token there, so the vhost must serve it from there.
    expect(config('server.certificates.challenge_root'))->toBe('/var/www/html');

    v7Certificate($this->site);
    $config = app((string) config('server.web_server_drivers.nginx.driver'))->renderConfig(
        $this->site->fresh(['domains', 'certificate', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($this->site),
    );

    expect($config)->toContain("location ^~ /.well-known/acme-challenge/ {\n        root /var/www/html;");
});
