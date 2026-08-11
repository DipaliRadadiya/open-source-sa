<?php

namespace Tests\Feature\Server;

use App\Enums\CertificateStatus;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\AppIssueDetector;
use App\Services\Server\Applications\DnsVerifier;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->systemUser = SystemUser::factory()->create();
    $this->application = Application::factory()->create(['system_user_id' => $this->systemUser->id]);
});

/**
 * Resolution is stubbed rather than left to the real resolver: a check that
 * calls out to DNS is a test whose result depends on the network it runs on,
 * and this one asserted "no drift" while actually resolving example.com.
 */
function fakeDns(?string $resolvesTo): void
{
    test()->swap(DnsVerifier::class, new class($resolvesTo) extends DnsVerifier
    {
        public function __construct(private ?string $ip)
        {
            // Deliberately does not call parent::__construct(): this stub
            // never runs a server operation.
        }

        public function resolve(string $domain): ?string
        {
            return $this->ip;
        }
    });
}

function makeDetector(): AppIssueDetector
{
    return app(AppIssueDetector::class);
}

// ---------------------------------------------------------------------------
// Healthy
// ---------------------------------------------------------------------------

it('returns empty issues for a healthy application', function () {
    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Certificate
// ---------------------------------------------------------------------------

it('flags an expired certificate', function () {
    Certificate::factory()->create([
        'application_id' => $this->application->id,
        'status' => CertificateStatus::Active,
        'expires_at' => now()->subDays(5),
    ]);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()['type'])->toBe('certificate')
        ->and($issues->first()['severity'])->toBe('critical');
});

it('flags DNS drift when stored IP no longer matches current resolution', function () {
    fakeDns('5.6.7.8');
    $domain = ApplicationDomain::factory()->create([
        'application_id' => $this->application->id,
        'type' => DomainType::Primary,
        'domain' => 'example.com',
        'dns_resolved_ip' => '1.2.3.4',
        'dns_verified_at' => now()->subHour(),
    ]);

    // Simulate the IP having changed since the last verification.
    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()['type'])->toBe('dns')
        ->and($issues->first()['severity'])->toBe('warning')
        ->and($issues->first()['meta']['stored_ip'])->toBe('1.2.3.4');
});

it('does not flag DNS when resolved IP matches stored IP', function () {
    fakeDns('1.2.3.4');
    ApplicationDomain::factory()->create([
        'application_id' => $this->application->id,
        'type' => DomainType::Primary,
        'domain' => 'example.com',
        'dns_resolved_ip' => '1.2.3.4',
    ]);

    $issues = makeDetector()->issues($this->application);

    expect($issues->where('type', 'dns'))->toHaveCount(0);
});

it('flags a certificate expiring within 30 days', function () {
    Certificate::factory()->create([
        'application_id' => $this->application->id,
        'status' => CertificateStatus::Active,
        'expires_at' => now()->addDays(20),
    ]);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()['type'])->toBe('certificate')
        ->and($issues->first()['severity'])->toBe('warning');
});

it('flags a certificate expiring within 7 days as critical', function () {
    Certificate::factory()->create([
        'application_id' => $this->application->id,
        'status' => CertificateStatus::Active,
        'expires_at' => now()->addDays(5),
    ]);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()['severity'])->toBe('critical');
});

it('does not flag a certificate with more than 30 days remaining', function () {
    Certificate::factory()->create([
        'application_id' => $this->application->id,
        'status' => CertificateStatus::Active,
        'expires_at' => now()->addDays(90),
    ]);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Worker
// ---------------------------------------------------------------------------

it('does not flag a stopped worker when the app has no start command', function () {
    // The app has no process (no start command), so the worker check is skipped.
    expect(makeDetector()->issues($this->application))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// PHP EOL
// ---------------------------------------------------------------------------

it('flags PHP 8.0 as EOL', function () {
    $this->application->update(['php_version' => '8.0']);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()['type'])->toBe('php_eol');
});

it('does not flag a current PHP version', function () {
    $this->application->update(['php_version' => '8.4']);

    expect(makeDetector()->issues($this->application))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Deploy failed
// ---------------------------------------------------------------------------

it('flags a failed last deploy', function () {
    $this->application->update(['failed_step' => 'build']);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()['type'])->toBe('deploy_failed')
        ->and($issues->first()['severity'])->toBe('warning');
});

it('does not flag when failed_step is null', function () {
    $this->application->update(['failed_step' => null]);

    expect(makeDetector()->issues($this->application))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Multiple issues
// ---------------------------------------------------------------------------

it('returns multiple issues at once', function () {
    $this->application->update(['php_version' => '8.0', 'failed_step' => 'deploy']);

    $issues = makeDetector()->issues($this->application);

    expect($issues)->toHaveCount(2);
});
