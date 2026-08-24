<?php

use App\Actions\Server\Application\ApplyVhost;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Http\Resources\CertificateResource;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-certs-'.getmypid();
    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.web_server_drivers.apache.sites_dir' => $this->home.'/sites',
        'server.web_server_drivers.openlitespeed.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'certuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    // DNS already verified — the gate itself is covered by its own test below.
    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
        'dns_verified_at' => now(),
        'dns_resolved_ip' => '203.0.113.10',
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
});

it('uses HTTPS only when an active certificate covers the primary hostname', function () {
    expect($this->application->fresh()->url())->toBe('http://shop.example.com');

    $certificate = activeCertificate($this->application);
    expect($this->application->fresh()->url())->toBe('https://shop.example.com');

    $certificate->update(['domains' => ['other.example.com']]);
    expect($this->application->fresh()->url())->toBe('http://shop.example.com');

    $certificate->update(['domains' => ['*.example.com']]);
    expect($this->application->fresh()->url())->toBe('https://shop.example.com');

    $this->application->update(['domain' => 'deep.shop.example.com']);
    expect($this->application->fresh()->url())->toBe('http://deep.shop.example.com');

    $this->application->update(['domain' => 'example.com']);
    expect($this->application->fresh()->url())->toBe('http://example.com');
});

it('queues issuance and reports 202 rather than pretending it is done', function () {
    Queue::fake();

    // `force` skips the reachability dry run, which has its own test file. This
    // one is about what the endpoint returns, not about the gate in front of it.
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt', 'force' => true])
        ->assertStatus(202)
        ->assertJsonPath('certificate.status', 'pending')
        ->assertJsonPath('certificate.domains.0', 'shop.example.com');

    Queue::assertPushed(IssueCertificate::class);
});

it('refuses to request a certificate for a domain that is not reachable', function () {
    $this->application->domains()->update(['dns_verified_at' => null, 'dns_resolved_ip' => null]);

    // Not politeness — Let's Encrypt allows five failed authorisations per
    // hostname per hour, so an unchecked attempt locks the user out of the fix.
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain');

    expect(Certificate::count())->toBe(0);
});

it('issues, records the paths and puts TLS into the vhost', function () {
    $certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Pending,
        'domains' => ['shop.example.com'],
    ]);

    fakeCertbotSuccess();

    (new IssueCertificate($certificate->id))->handle(
        app(CertbotClient::class),
        app(CertificateFiles::class),
        app(ApplyVhost::class),
        app(WebServerManager::class),
        app(ActivityLogger::class),
    );

    $certificate->refresh();

    expect($certificate->status)->toBe(CertificateStatus::Active)
        ->and($certificate->certificate_path)->toBe('/etc/letsencrypt/live/shop.example.com/fullchain.pem')
        ->and($certificate->expires_at?->format('Y-m-d'))->toBe('2030-01-01')
        ->and($this->application->fresh()->url())->toBe('https://shop.example.com');

    Process::assertRan(fn ($process) => in_array('option', $process->command, true)
        && in_array('home', $process->command, true)
        && in_array('https://shop.example.com', $process->command, true));

    $config = renderedCertVhost($this->application);

    expect($config)->toContain('listen 443 ssl')
        ->and($config)->toContain('/etc/letsencrypt/live/shop.example.com/fullchain.pem');

    $this->assertDatabaseHas('activity_logs', ['type' => 'application', 'action' => 'certificate_issued']);
});

it('drives certbot through the webroot plugin, never the nginx one', function () {
    $certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Pending,
        'domains' => ['shop.example.com'],
    ]);

    fakeCertbotSuccess();

    (new IssueCertificate($certificate->id))->handle(
        app(CertbotClient::class),
        app(CertificateFiles::class),
        app(ApplyVhost::class),
        app(WebServerManager::class),
        app(ActivityLogger::class),
    );

    // The `--nginx` and `--apache` plugins work by editing the vhost, which the
    // panel regenerates on every domain change — their edits would be wiped and
    // HTTPS would vanish with nothing to explain it.
    Process::assertRan(fn ($process) => in_array('certonly', $process->command, true)
        && in_array('--webroot', $process->command, true)
        && ! in_array('--nginx', $process->command, true));
});

it('classifies a rate limit rather than telling the user to try again', function () {
    $certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Pending,
        'domains' => ['shop.example.com'],
    ]);

    Process::fake(function ($process) {
        if (in_array('certonly', $process->command, true)) {
            return Process::result(
                output: '',
                errorOutput: 'Error creating new order :: too many certificates (5) already issued for this exact set of identifiers',
                exitCode: 1,
            );
        }

        return Process::result(exitCode: 0);
    });

    (new IssueCertificate($certificate->id))->handle(
        app(CertbotClient::class),
        app(CertificateFiles::class),
        app(ApplyVhost::class),
        app(WebServerManager::class),
        app(ActivityLogger::class),
    );

    $certificate->refresh();

    expect($certificate->status)->toBe(CertificateStatus::Failed)
        ->and($certificate->reason)->toBe('rate_limited')
        // Retrying is exactly what must not happen, so the message has to say
        // when — not "something went wrong".
        ->and($certificate->message())->toContain('week');
});

it('never puts certbot output in front of the user', function () {
    $certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Failed,
        'domains' => ['shop.example.com'],
        'reason' => 'unreachable',
    ]);

    $rendered = CertificateResource::make($certificate)->resolve();

    expect($rendered['message'])->toBe(__('certificate.failed.unreachable'))
        ->and($rendered['reason'])->toBe('unreachable');
});

it('leaves a readable row when the job dies outright', function () {
    $certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Issuing,
        'domains' => ['shop.example.com'],
    ]);

    // Without this the row sits on `issuing` forever and the only honest thing
    // the screen could render is a spinner that never stops.
    (new IssueCertificate($certificate->id))->failed(new RuntimeException('worker died'));

    expect($certificate->fresh()->status)->toBe(CertificateStatus::Failed);
});

it('rejects TLS without pointing at certificate files while issuance is pending', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Pending,
        'domains' => ['shop.example.com'],
    ]);

    $config = renderedCertVhost($this->application->fresh());

    // The hostname must still own 443 or nginx falls through to another site's
    // SSL vhost. It rejects the handshake without naming the not-yet-created
    // certbot files.
    expect($config)->toContain('listen 443 ssl')
        ->and($config)->toContain('ssl_reject_handshake on')
        ->and($config)->not->toContain('/etc/letsencrypt/live/shop.example.com');
});

it('refuses to force HTTPS without a working certificate', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Pending,
        'domains' => ['shop.example.com'],
    ]);

    // Not a preference. Redirecting HTTP into the TLS rejection vhost takes
    // the site off the internet for every visitor at once.
    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/certificate/force-https", ['force_https' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('force_https');
});

it('redirects to HTTPS while keeping the ACME path reachable on port 80', function () {
    activeCertificate($this->application);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/certificate/force-https", ['force_https' => true])
        ->assertOk()
        ->assertJsonPath('certificate.force_https', true);

    $config = renderedCertVhost($this->application->fresh());

    expect($config)->toContain('return 301 https://$host$request_uri')
        // Without this exception renewal stops working and the redirect goes on
        // pointing confidently at a certificate that has expired.
        ->and($config)->toContain('location ^~ /.well-known/acme-challenge/');
});

it('installs an uploaded certificate synchronously and stops claiming it renews', function () {
    [$pem, $key] = generateKeyPair('shop.example.com');

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", [
            'type' => 'custom',
            'certificate' => $pem,
            'private_key' => $key,
        ])
        ->assertCreated()
        ->assertJsonPath('certificate.type', 'custom')
        ->assertJsonPath('certificate.status', 'active')
        // Nothing can renew an uploaded certificate; saying otherwise would be
        // a promise the panel cannot keep.
        ->assertJsonPath('certificate.renewable', false)
        ->assertJsonPath('certificate.auto_renew', false);

    // The key goes over stdin, never in a command — otherwise it is readable in
    // `ps` and lands in the server-ops log.
    Process::assertRan(fn ($process) => in_array('tee', $process->command, true)
        && str_contains((string) $process->input, 'PRIVATE KEY'));

    Process::assertNotRan(fn ($process) => collect($process->command)
        ->contains(fn ($part) => str_contains((string) $part, 'PRIVATE KEY')));
});

it('rejects a private key that does not match the certificate', function () {
    [$pem] = generateKeyPair('shop.example.com');
    [, $otherKey] = generateKeyPair('other.example.com');

    // Written happily by the filesystem, fails the config test, takes the site
    // down over a copy-paste. Caught while nothing has changed.
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", [
            'type' => 'custom',
            'certificate' => $pem,
            'private_key' => $otherKey,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('private_key');
});

it('rejects something that is not a PEM file at all', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", [
            'type' => 'custom',
            'certificate' => 'not a certificate',
            'private_key' => 'not a key',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['certificate', 'private_key']);
});

it('names the domains that are on the site but not on the certificate', function () {
    activeCertificate($this->application);

    $this->application->domains()->create([
        'domain' => 'later.example.com',
        'type' => DomainType::Alias,
        'dns_verified_at' => now(),
    ]);

    // The failure that appears only in the visitor's browser: a name added
    // after issuance is served by a certificate that does not mention it.
    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate")
        ->assertOk();

    expect($response->json('certificate.missing_domains'))->toBe(['later.example.com']);
});

it('clears force HTTPS before rewriting the vhost when the certificate is removed', function () {
    $certificate = activeCertificate($this->application);
    $certificate->update(['force_https' => true]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/certificate")
        ->assertNoContent();

    $config = renderedCertVhost($this->application->fresh());

    // Rewriting first would leave a config that redirects every visitor into
    // the TLS rejection vhost — not "no HTTPS", but no usable site.
    expect($config)->not->toContain('return 301 https://$host')
        ->and($config)->toContain('listen 80')
        ->and($this->application->fresh()->url())->toBe('http://shop.example.com');

    Process::assertRan(fn ($process) => in_array('option', $process->command, true)
        && in_array('home', $process->command, true)
        && in_array('http://shop.example.com', $process->command, true));

    Process::assertRan(fn ($process) => in_array('delete', $process->command, true)
        && in_array('--cert-name', $process->command, true));
});

it('keeps a failed certbot cleanup retryable and does not report success', function () {
    $certificate = activeCertificate($this->application);

    Process::fake(function ($process) {
        if (in_array('delete', $process->command, true)) {
            return Process::result(errorOutput: 'certbot cleanup failed', exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/certificate")
        ->assertStatus(500);

    expect($certificate->fresh()->status)->toBe(CertificateStatus::Pending)
        ->and($this->application->fresh()->url())->toBe('http://shop.example.com');

    $this->assertDatabaseMissing('activity_logs', [
        'type' => 'application',
        'action' => 'certificate_removed',
    ]);

    // The retained row is the retry state. A second request completes cleanup
    // rather than claiming there is nothing left to remove.
    Process::fake(fn () => Process::result(exitCode: 0));

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/certificate")
        ->assertNoContent();

    expect($certificate->fresh())->toBeNull();
});

it('removes uploaded and self-signed certificate files before deleting their rows', function (CertificateType $type) {
    $certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => $type,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/ssl/sv-oss/original.example.com.crt',
        'private_key_path' => '/etc/ssl/sv-oss/original.example.com.key',
        'chain_path' => null,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/certificate")
        ->assertNoContent();

    Process::assertRan(fn ($process) => in_array('rm', $process->command, true)
        && in_array('/etc/ssl/sv-oss/original.example.com.crt', $process->command, true)
        && in_array('/etc/ssl/sv-oss/original.example.com.key', $process->command, true));

    expect($certificate->fresh())->toBeNull();
})->with([CertificateType::Custom, CertificateType::SelfSigned]);

it('refuses a stored certificate path that escapes the private certificate directory', function () {
    Process::fake();

    expect(fn () => app(CertificateFiles::class)->remove([
        '/etc/ssl/sv-oss/../../etc/passwd',
    ], $this->application->id))->toThrow(HttpException::class);

    Process::assertNotRan(fn ($process) => in_array('rm', $process->command, true));
});

it('rolls a partial application URL downgrade back when synchronization fails', function () {
    $certificate = activeCertificate($this->application);

    Process::fake(function ($process) {
        if (in_array('siteurl', $process->command, true)
            && in_array('http://shop.example.com', $process->command, true)) {
            return Process::result(errorOutput: 'second option failed', exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/certificate")
        ->assertStatus(500);

    expect($certificate->fresh()->status)->toBe(CertificateStatus::Active)
        ->and($this->application->fresh()->url())->toBe('https://shop.example.com');

    Process::assertRan(fn ($process) => in_array('home', $process->command, true)
        && in_array('http://shop.example.com', $process->command, true));
    Process::assertRan(fn ($process) => in_array('home', $process->command, true)
        && in_array('https://shop.example.com', $process->command, true));
});

it('renders an isolated no-certificate TLS sink for every web server', function (string $driver) {
    config(['server.web_server' => $driver]);

    $config = renderedCertVhost($this->application->fresh(), $driver);

    expect($config)->toContain('shop.example.com');

    match ($driver) {
        'nginx' => expect($config)->toContain('ssl_reject_handshake on'),
        'apache' => expect($config)
            ->toContain('<VirtualHost *:443>')
            ->toContain('/etc/ssl/sv-oss/.panel-tls-reject.crt')
            ->toContain('Require all denied'),
        'openlitespeed' => expect($config)
            ->toContain('/etc/ssl/sv-oss/.panel-tls-reject.crt')
            ->toContain('RewriteCond %{HTTPS} =on')
            ->toContain('RewriteRule ^ - [F,L]'),
    };
})->with(['nginx', 'apache', 'openlitespeed']);

it('never serves an SSL-enabled Craft site for a certificate-less Mautic hostname', function (string $driver) {
    $this->application->update(['site_type' => 'mautic']);

    $craft = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Craft',
        'slug' => 'craft',
        'domain' => 'craft.example.com',
        'site_type' => 'craftcms',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/web',
        'status' => 'active',
    ]);
    $craft->domains()->create([
        'domain' => 'craft.example.com',
        'type' => DomainType::Primary,
        'dns_verified_at' => now(),
    ]);
    Certificate::create([
        'application_id' => $craft->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['craft.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/craft.example.com/fullchain.pem',
        'private_key_path' => '/etc/letsencrypt/live/craft.example.com/privkey.pem',
    ]);

    $mauticConfig = renderedCertVhost($this->application->fresh(), $driver);
    $craftConfig = renderedCertVhost($craft->fresh(), $driver);

    expect($mauticConfig)->toContain('shop.example.com')
        ->not->toContain('/etc/letsencrypt/live/craft.example.com')
        ->and($craftConfig)->toContain('/etc/letsencrypt/live/craft.example.com/fullchain.pem');

    match ($driver) {
        'nginx' => expect($mauticConfig)->toContain('ssl_reject_handshake on'),
        'apache' => expect($mauticConfig)->toContain('Require all denied'),
        'openlitespeed' => expect($mauticConfig)->toContain('RewriteCond %{HTTPS} =on'),
    };
})->with(['nginx', 'apache', 'openlitespeed']);

it('creates the shared TLS rejection certificate lazily on a brownfield server', function () {
    Process::fake(function ($process) {
        $command = $process->command[0] === 'sudo'
            ? array_slice($process->command, 2)
            : $process->command;

        if (($command[0] ?? null) === 'test'
            && in_array('/etc/ssl/sv-oss/.panel-tls-reject.crt', $command, true)) {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    $driver = app((string) config('server.web_server_drivers.apache.driver'));
    $result = $driver->apply($this->application->fresh(['domains', 'certificate', 'systemUser']), $this->application->documentRoot());

    expect($result->ok)->toBeTrue();

    Process::assertRan(function ($process) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return ($command[0] ?? null) === 'openssl' && in_array('/CN=unmatched.invalid', $command, true);
    });
    Process::assertRan(function ($process) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return ($command[0] ?? null) === 'chmod'
            && in_array('/etc/ssl/sv-oss/.panel-tls-reject.key', $command, true);
    });
});

it('returns null rather than 404 when a site has no certificate', function () {
    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate")
        ->assertOk()
        ->assertJsonPath('certificate', null);
});

it('needs manage on app_domain to change anything', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_domain');

    $this->actingAs($viewer)
        ->getJson("/api/applications/{$this->application->id}/certificate")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertForbidden();
});

it('lets a self-signed certificate cover a name Let\'s Encrypt could never reach', function () {
    Queue::fake();

    // The one case where unresolvable names are the point: an internal or
    // staging hostname. Requiring DNS here would refuse the only situation
    // self-signing exists for.
    $this->application->domains()->update(['dns_verified_at' => null]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'self_signed'])
        ->assertStatus(202)
        ->assertJsonPath('certificate.renewable', false);
});

it('renders TLS on all three web servers', function (string $driver) {
    config(['server.web_server' => $driver]);

    activeCertificate($this->application);

    $config = renderedCertVhost($this->application->fresh(), $driver);

    expect($config)->toContain('/etc/letsencrypt/live/shop.example.com/fullchain.pem')
        ->and($config)->toContain('/etc/letsencrypt/live/shop.example.com/privkey.pem');
})->with(['nginx', 'apache', 'openlitespeed']);

/**
 * certbot succeeds, and `openssl x509 -enddate` answers for the file it
 * supposedly wrote — the expiry is read off disk rather than assumed from a
 * lifetime, because Let's Encrypt has begun issuing shorter-lived certificates.
 */
function fakeCertbotSuccess(): void
{
    Process::fake(function ($process) {
        if (in_array('-enddate', $process->command, true)) {
            return Process::result(output: 'notAfter=Jan  1 00:00:00 2030 GMT');
        }

        return Process::result(exitCode: 0);
    });
}

function activeCertificate(Application $application): Certificate
{
    return Certificate::create([
        'application_id' => $application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/shop.example.com/fullchain.pem',
        'private_key_path' => '/etc/letsencrypt/live/shop.example.com/privkey.pem',
        'issued_at' => now(),
        'expires_at' => now()->addDays(89),
    ]);
}

function renderedCertVhost(Application $application, ?string $driverName = null): string
{
    $driver = $driverName === null
        ? app(WebServerManager::class)->driver()
        : app((string) config("server.web_server_drivers.{$driverName}.driver"));

    return $driver->renderConfig(
        $application->fresh(['domains', 'certificate', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($application),
    );
}

/**
 * A real self-signed pair, so the key/certificate matching is exercised against
 * OpenSSL rather than against a fixture that only looks right.
 *
 * @return array{0: string, 1: string}
 */
function generateKeyPair(string $commonName): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

    openssl_x509_export($cert, $pem);
    openssl_pkey_export($key, $privateKey);

    return [$pem, $privateKey];
}
