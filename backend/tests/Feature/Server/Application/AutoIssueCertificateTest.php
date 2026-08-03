<?php

use App\Actions\Server\Application\AutoIssueCertificate;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\DnsVerifier;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-auto-'.getmypid();
    config([
        'server.web_server' => 'nginx',
        'server.certificates.challenge_root' => $this->home.'/acme',
        'server.certificates.auto_issue' => true,
    ]);

    $systemUser = SystemUser::create([
        'username' => 'autouser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.example.com', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);

    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
    ]);

    $this->serverIp = '203.0.113.10';
    Cache::put('server.public_ip', $this->serverIp, now()->addHour());

    Process::fake(fn () => Process::result(exitCode: 0));
    Queue::fake();
});

function pointDnsHere(?string $ip = null): void
{
    $ip ??= test()->serverIp;

    test()->mock(DnsVerifier::class, function ($mock) use ($ip) {
        $mock->shouldReceive('verify')->andReturnUsing(function ($domain) use ($ip) {
            $domain->update(['dns_resolved_ip' => $ip, 'behind_proxy' => false]);

            return $domain;
        });
        $mock->shouldReceive('serverIp')->andReturn(test()->serverIp);
    });
}

function challengeAnswers(): void
{
    Http::fake(fn ($request) => Http::response(
        basename(parse_url($request->url(), PHP_URL_PATH))."\n", 200
    ));
}

it('issues on its own when the domain already points here', function () {
    pointDnsHere();
    challengeAnswers();

    // The case this exists for: a site migrated from another server, or a
    // record pointed before the site was created. HTTPS simply exists.
    app(AutoIssueCertificate::class)->attempt($this->application);

    $certificate = Certificate::first();

    expect($certificate)->not->toBeNull()
        ->and($certificate->type)->toBe(CertificateType::LetsEncrypt)
        ->and($certificate->status)->toBe(CertificateStatus::Pending);

    Queue::assertPushed(IssueCertificate::class);
});

it('writes nothing at all when the domain is not pointed here yet', function () {
    pointDnsHere('198.51.100.5');
    Http::fake();

    app(AutoIssueCertificate::class)->attempt($this->application);

    // The important assertion in this file. A failed row here would mean every
    // new site opens on a red SSL error about something the user has not set
    // up yet — for a new domain, which is the normal case.
    expect(Certificate::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('leaves no trace in the activity log when it declines', function () {
    pointDnsHere('198.51.100.5');
    Http::fake();

    app(AutoIssueCertificate::class)->attempt($this->application);

    $this->assertDatabaseMissing('activity_logs', ['type' => 'application', 'action' => 'certificate_requested']);
});

it('never spends the shared nip.io limit automatically', function () {
    $this->application->domains()->update(['is_test' => true]);

    pointDnsHere();
    challengeAnswers();

    // Every certificate issued for nip.io anywhere in the world shares one
    // weekly limit. Spending it automatically, on every site created on every
    // install of this panel, would be antisocial.
    app(AutoIssueCertificate::class)->attempt($this->application->fresh(['domains']));

    expect(Certificate::count())->toBe(0);
    Http::assertNothingSent();
});

it('does not touch an application that already has a certificate', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::Custom,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/ssl/sv-oss/shop.example.com.crt',
        'private_key_path' => '/etc/ssl/sv-oss/shop.example.com.key',
    ]);

    pointDnsHere();
    challengeAnswers();

    // Provisioning can be re-run. Reissuing over a working certificate spends
    // rate limit to achieve nothing, and would replace an uploaded one.
    app(AutoIssueCertificate::class)->attempt($this->application->fresh(['domains', 'certificate']));

    expect(Certificate::first()->type)->toBe(CertificateType::Custom);
    Queue::assertNothingPushed();
});

it('can be turned off for a box with no public DNS', function () {
    config(['server.certificates.auto_issue' => false]);

    pointDnsHere();
    challengeAnswers();

    app(AutoIssueCertificate::class)->attempt($this->application);

    expect(Certificate::count())->toBe(0);
    Http::assertNothingSent();
});

it('cannot break the provision it runs at the end of', function () {
    pointDnsHere();

    // A DNS timeout must not turn a site that is created, serving and correct
    // into a failed application over a certificate nobody asked for.
    Http::fake(fn () => throw new ConnectionException('the network went away'));

    app(AutoIssueCertificate::class)->attempt($this->application);

    expect(Certificate::count())->toBe(0)
        ->and($this->application->fresh()->status->value)->toBe('active');
});
