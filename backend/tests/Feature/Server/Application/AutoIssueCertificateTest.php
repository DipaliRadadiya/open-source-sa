<?php

use App\Actions\Server\Application\AutoIssueCertificate;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Http\Resources\ApplicationResource;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\DnsVerifier;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
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

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop', 'domain' => 'shop.example.com', 'site_type' => 'wordpress',
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

it('puts the certificate ahead of queued installs when the worker reads the priority queue', function (string $worker, ?string $queue) {
    pointDnsHere();
    challengeAnswers();
    fakeRunningWorker($worker);

    // nginx QA #4: a site was active for 22 minutes before its certificate,
    // queued behind sixteen installs, ran. Only to `high` when the running
    // worker reads it — a job on an unread queue never runs.
    app(AutoIssueCertificate::class)->attempt($this->application);

    Queue::assertPushed(IssueCertificate::class, fn (IssueCertificate $job) => $job->queue === $queue);
})->with([
    'worker reads high' => ['artisan queue:work --queue=high,default --sleep=3', 'high'],
    'worker from before the priority queue' => ['artisan queue:work --sleep=3', null],
]);

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

it('treats a wildcard-DNS hostname like any other name that points here', function () {
    $this->application->domains()->update(['is_test' => true]);

    pointDnsHere();
    challengeAnswers();

    // The suffix used to disqualify a name outright, which made a second class
    // of domain the panel would not protect. What decides is the dry run —
    // whether Let's Encrypt could really validate this name — and it answers
    // the same for every hostname.
    app(AutoIssueCertificate::class)->attempt($this->application->fresh(['domains']));

    expect(Certificate::count())->toBe(1);
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

/*
 * Bug #52: Blank PHP, Static, Git, Staging and Clone sites reach this step
 * straight after `systemctl reload`, which returns before the new workers take
 * over — the first challenge fetch got the old workers' 404, and no
 * certificate was issued. Measured on the nginx test server: minutes later the
 * same check passed. One-click apps, installing for a while first, never hit it.
 */
it('asks again when the web server has not picked up the new site yet', function () {
    pointDnsHere();
    $calls = 0;
    Http::fake(function ($request) use (&$calls) {
        $calls++;

        // Old workers for the first two fetches, then the new config.
        return $calls <= 2
            ? Http::response('Not Found', 404)
            : Http::response(basename(parse_url($request->url(), PHP_URL_PATH))."\n", 200);
    });

    app(AutoIssueCertificate::class)->attempt($this->application);

    expect(Certificate::count())->toBe(1)->and($calls)->toBe(3);
    Queue::assertPushed(IssueCertificate::class);
});

it('gives up quietly after a few tries, and does not retry a domain pointed elsewhere', function () {
    pointDnsHere();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response('Not Found', 404);
    });

    app(AutoIssueCertificate::class)->attempt($this->application);

    expect(Certificate::count())->toBe(0)
        ->and($calls)->toBe((int) config('server.certificates.auto_issue_attempts'));

    // Pointed elsewhere is a real answer: asked once, never fetched.
    pointDnsHere('198.51.100.5');
    $calls = 0;

    app(AutoIssueCertificate::class)->attempt($this->application->fresh(['domains', 'certificate']));

    expect($calls)->toBe(0)->and(Certificate::count())->toBe(0);
});

it('checks a domain pointed elsewhere once, so a new site does not wait for nothing', function () {
    $checks = 0;
    $this->mock(DnsVerifier::class, function ($mock) use (&$checks) {
        $mock->shouldReceive('verify')->andReturnUsing(function ($domain) use (&$checks) {
            $checks++;
            $domain->update(['dns_resolved_ip' => '198.51.100.5', 'behind_proxy' => false]);

            return $domain;
        });
        $mock->shouldReceive('serverIp')->andReturn(test()->serverIp);
    });
    Http::fake();

    app(AutoIssueCertificate::class)->attempt($this->application);

    expect($checks)->toBe(1)->and(Certificate::count())->toBe(0);
});

it('says HTTPS is on its way while the certificate it queued has not been served yet (DS-14)', function (?CertificateStatus $status, bool $pending) {
    // DS-11: n8n, freshrss, it-tools, stirling-pdf and adminer went active
    // 10–60 s before their certificate was served, and https:// failed the
    // TLS handshake in between with nothing in the API to say why.
    if ($status !== null) {
        Certificate::create([
            'application_id' => $this->application->id,
            'type' => CertificateType::LetsEncrypt,
            'status' => $status,
            'domains' => ['shop.example.com'],
        ]);
    }

    $request = Request::create('/');
    $request->setUserResolver(fn () => User::query()->first());
    $shown = ApplicationResource::make($this->application->fresh())->toArray($request);

    expect($shown['certificate_pending'])->toBe($pending)
        ->and($shown['url'])->toBe('http://shop.example.com');
})->with([
    'none asked for' => [null, false],
    'queued' => [CertificateStatus::Pending, true],
    'issuing' => [CertificateStatus::Issuing, true],
    'failed' => [CertificateStatus::Failed, false],
]);
