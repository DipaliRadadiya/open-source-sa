<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Jobs\DryRunCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\DnsVerifier;
use App\Services\Server\Certificates\AcmeReachabilityCheck;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateDryRunStore;
use App\Services\Server\ServerOpsResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-dryrun-'.getmypid();
    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.certificates.challenge_root' => $this->home.'/acme',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'dryuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'web_root' => '/',
        'php_version' => '8.4',
        'status' => 'active',
    ]);

    // Deliberately unverified, like the precheck suite. A dry run that refused
    // to look at an unverified domain would be useless in exactly the case a
    // user reaches for it.
    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
    ]);

    $this->serverIp = '203.0.113.10';
    Cache::put('server.public_ip', $this->serverIp, now()->addHour());

    Process::fake(fn () => Process::result(exitCode: 0));
    Queue::fake();
});

/** Point every domain at this server. */
function dryRunDns(string $ip): void
{
    test()->mock(DnsVerifier::class, function ($mock) use ($ip) {
        $mock->shouldReceive('verify')->andReturnUsing(function ($domain) use ($ip) {
            $domain->update([
                'dns_resolved_ip' => $ip,
                'behind_proxy' => false,
                'dns_verified_at' => $ip === test()->serverIp ? now() : null,
            ]);

            return $domain;
        });
        $mock->shouldReceive('serverIp')->andReturn(test()->serverIp);
    });
}

/** The challenge path answers with the exact token it was asked for. */
function dryRunTokenServed(): void
{
    Http::fake(fn ($request) => Http::response(
        basename(parse_url($request->url(), PHP_URL_PATH))."\n", 200
    ));
}

/** Run the queued job for real, with certbot's output stubbed. */
function runDryRunJob(string $output, bool $ok = true): void
{
    test()->mock(CertbotClient::class, function ($mock) use ($output, $ok) {
        $mock->shouldReceive('ensureChallengeRoot')->andReturn(new ServerOpsResult(true, 'ref-root'));
        $mock->shouldReceive('dryRun')->andReturn(
            new ServerOpsResult($ok, 'ref-dryrun', Process::result(output: $output, exitCode: $ok ? 0 : 1))
        );
        $mock->shouldReceive('confirmedDryRun')->andReturnUsing(
            fn (string $text) => str_contains(strtolower($text), 'the dry run was successful')
        );
        $mock->shouldReceive('classify')->andReturnUsing(
            fn (string $text) => str_contains(strtolower($text), 'too many failed authorizations')
                ? 'rate_limited_failures'
                : 'unknown'
        );
    });

    app(DryRunCertificate::class, ['applicationId' => test()->application->id])
        ->handle(
            app(AcmeReachabilityCheck::class),
            app(CertbotClient::class),
            app(CertificateDryRunStore::class),
            app(ActivityLogger::class),
        );
}

it('queues a dry run and creates no certificate', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertStatus(202);

    expect($response->json('dry_run.status'))->toBe('running');

    Queue::assertPushed(DryRunCertificate::class);

    // The entire promise of the feature: nothing about the site changed.
    expect(Certificate::count())->toBe(0);
});

it('refuses a viewer who cannot manage domains', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses while an issuance is in flight, rather than colliding on certbot\'s lock', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Issuing,
        'domains' => ['shop.example.com'],
        'auto_renew' => true,
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertStatus(422)
        ->assertJsonValidationErrors('certificate');

    Queue::assertNothingPushed();
});

it('does not start a second job while one is already running', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertStatus(202);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertStatus(202);

    // Two certbot processes contend for /var/lib/letsencrypt's lock, and one
    // of them reports a failure that says nothing about the domain.
    Queue::assertPushed(DryRunCertificate::class, 1);
});

it('reports no dry run for a site that has never had one', function () {
    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertOk()
        ->assertJson(['dry_run' => null]);
});

it('passes when the token is served and certbot confirms the dry run', function () {
    dryRunDns($this->serverIp);
    dryRunTokenServed();

    app(CertificateDryRunStore::class)->start($this->application->id);
    runDryRunJob('Simulating a certificate request for shop.example.com'.PHP_EOL.'The dry run was successful.');

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertOk();

    expect($response->json('dry_run.status'))->toBe('passed');
    expect($response->json('dry_run.stage'))->toBe('acme');
    expect($response->json('dry_run.domains.0.ok'))->toBeTrue();
    expect($response->json('dry_run.domains.0.message'))->toContain('ready');

    // Still nothing issued, still nothing stored.
    expect(Certificate::count())->toBe(0);
});

it('never reaches certbot when no domain serves the token', function () {
    dryRunDns($this->serverIp);

    // A WordPress 404 page returned with HTTP 200.
    Http::fake(fn () => Http::response('<html>Not found</html>', 200));

    $this->mock(CertbotClient::class, function ($mock) {
        $mock->shouldReceive('ensureChallengeRoot')->andReturn(new ServerOpsResult(true, 'ref-root'));
        // Spending a real authorisation failure to learn what a local HTTP
        // request just established for free is the thing this stage prevents.
        $mock->shouldNotReceive('dryRun');
    });

    app(CertificateDryRunStore::class)->start($this->application->id);

    app(DryRunCertificate::class, ['applicationId' => $this->application->id])
        ->handle(
            app(AcmeReachabilityCheck::class),
            app(CertbotClient::class),
            app(CertificateDryRunStore::class),
            app(ActivityLogger::class),
        );

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertOk();

    expect($response->json('dry_run.status'))->toBe('failed');
    expect($response->json('dry_run.stage'))->toBe('reachability');
    expect($response->json('dry_run.domains.0.message'))->toContain('/.well-known/');
});

it('reports the CA\'s refusal with the reason, not a bare failure', function () {
    dryRunDns($this->serverIp);
    dryRunTokenServed();

    app(CertificateDryRunStore::class)->start($this->application->id);
    runDryRunJob('Error: too many failed authorizations recently', ok: false);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertOk();

    expect($response->json('dry_run.status'))->toBe('failed');
    expect($response->json('dry_run.reason'))->toBe('rate_limited_failures');
    expect($response->json('dry_run.message'))->toContain('five');
    expect($response->json('dry_run.reference'))->toBe('ref-dryrun');
});

it('treats a zero exit that validated nothing as a failure', function () {
    dryRunDns($this->serverIp);
    dryRunTokenServed();

    app(CertificateDryRunStore::class)->start($this->application->id);

    // certonly exits 0 on this path. Reading the exit code alone would show a
    // green tick for an authorisation that never happened.
    runDryRunJob('Certificate not yet due for renewal; no action taken.');

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate/dry-run")
        ->assertOk();

    expect($response->json('dry_run.status'))->toBe('failed');
});

it('leaves a verdict rather than a spinner when the job dies', function () {
    app(CertificateDryRunStore::class)->start($this->application->id);

    (new DryRunCertificate($this->application->id))->failed(new RuntimeException('worker died'));

    $state = app(CertificateDryRunStore::class)->get($this->application->id);

    expect($state['status'])->toBe('failed');
    expect($state['reason'])->toBe('unknown');
});

it('builds the dry-run command from the same certonly invocation as a real issue', function () {
    $client = app(CertbotClient::class);

    $reflection = new ReflectionMethod($client, 'certonly');
    $command = $reflection->invoke($client, ['shop.example.com', 'www.shop.example.com'], 'ops@example.com', ['--dry-run', '--force-renewal']);

    // A simulation that drifts from the real command simulates nothing.
    expect($command)->toContain('certonly')
        ->and($command)->toContain('--webroot')
        ->and($command)->toContain('--expand')
        ->and($command)->toContain('--dry-run')
        // Without this certonly can decide there is nothing to do and exit 0
        // having validated nothing at all.
        ->and($command)->toContain('--force-renewal')
        ->and($command)->not->toContain('--keep-until-expiring');

    // --cert-name pins the lineage to the primary, exactly as issuing does.
    expect($command[array_search('--cert-name', $command, true) + 1])->toBe('shop.example.com');
});
