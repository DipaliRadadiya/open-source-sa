<?php

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
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-precheck-'.getmypid();
    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.certificates.challenge_root' => $this->home.'/acme',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'preuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    // Deliberately *not* verified. The whole point of the dry run is that the
    // stored flag is written when a domain is added — usually before the user
    // has touched their registrar — so relying on it leaves the button
    // disabled long after the problem is fixed.
    $this->primary = $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
    ]);

    $this->serverIp = '203.0.113.10';
    Cache::put('server.public_ip', $this->serverIp, now()->addHour());

    Process::fake(fn () => Process::result(exitCode: 0));
    Queue::fake();
});

/**
 * Point every domain at this server, and have the challenge path answer with
 * whatever the caller asks for.
 */
function fakeDns(string $ip): void
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

it('issues when the challenge path actually answers, even though DNS was never verified', function () {
    fakeDns($this->serverIp);

    // The token comes back exactly — the only thing that proves Let's Encrypt
    // will succeed.
    Http::fake(function ($request) {
        $token = basename(parse_url($request->url(), PHP_URL_PATH));

        return Http::response($token."\n", 200);
    });

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(202);

    Queue::assertPushed(IssueCertificate::class);
});

it('refuses and blames the rewrite rules when the site answers with the wrong body', function () {
    fakeDns($this->serverIp);

    // A WordPress 404 page returned with HTTP 200 — which is why the body is
    // compared rather than just the status.
    Http::fake(fn () => Http::response('<html>Not found</html>', 200));

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain');

    expect(Certificate::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses when nothing answers on port 80', function () {
    fakeDns($this->serverIp);

    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(422);

    expect($response->json('errors.domain.0'))->toContain('port 80');
});

it('names the address rather than fetching a stranger when DNS points elsewhere', function () {
    fakeDns('198.51.100.5');

    Http::fake();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(422);

    expect($response->json('errors.domain.0'))->toContain('198.51.100.5');

    // Never fetched: the check only ever talks to this server's own address,
    // so a domain pointed at somebody else cannot make the panel call them.
    Http::assertNothingSent();
});

it('refuses a domain resolving to loopback or the metadata range', function (string $ip) {
    fakeDns($ip);

    Http::fake();

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(422);

    Http::assertNothingSent();
})->with(['127.0.0.1', '169.254.169.254']);

it('issues for the domains that pass and leaves the ones that do not', function () {
    $this->application->domains()->create([
        'domain' => 'www.shop.example.com',
        'type' => DomainType::Alias,
    ]);

    $this->mock(DnsVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')->andReturnUsing(function ($domain) {
            // The apex is pointed; the www record has not propagated yet.
            $domain->update([
                'dns_resolved_ip' => $domain->domain === 'shop.example.com' ? $this->serverIp : null,
                'behind_proxy' => false,
            ]);

            return $domain;
        });
        $mock->shouldReceive('serverIp')->andReturn($this->serverIp);
    });

    Http::fake(function ($request) {
        return Http::response(basename(parse_url($request->url(), PHP_URL_PATH))."\n", 200);
    });

    // Blocking the whole request over the slower of two DNS records helps
    // nobody — the site gets HTTPS today and `missing_domains` says what is
    // left.
    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(202);

    expect($response->json('certificate.domains'))->toBe(['shop.example.com']);
});

it('tells the user to pause Cloudflare rather than reporting a generic failure', function () {
    $this->mock(DnsVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')->andReturnUsing(function ($domain) {
            $domain->update(['dns_resolved_ip' => '104.16.0.1', 'behind_proxy' => true]);

            return $domain;
        });
        $mock->shouldReceive('serverIp')->andReturn($this->serverIp);
    });

    Http::fake();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(422);

    expect($response->json('errors.domain.0'))->toContain('Cloudflare');
});

it('lets the user override the dry run for a server behind NAT', function () {
    fakeDns($this->serverIp);

    // The dry run fails because the box cannot reach its own public address —
    // while the real challenge, arriving from outside, would succeed. Refusing
    // outright would make the feature unusable on those servers.
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", [
            'type' => 'letsencrypt',
            'force' => true,
        ])
        ->assertStatus(202);

    Queue::assertPushed(IssueCertificate::class);
});

it('does not run the dry run for a self-signed certificate', function () {
    Http::fake();

    // Unresolvable names are the entire point of self-signing — an internal or
    // staging hostname Let's Encrypt could never validate.
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'self_signed'])
        ->assertStatus(202);

    Http::assertNothingSent();
});

it('cleans up the token it wrote', function () {
    fakeDns($this->serverIp);

    Http::fake(fn ($request) => Http::response(basename(parse_url($request->url(), PHP_URL_PATH))."\n", 200));

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/certificate", ['type' => 'letsencrypt'])
        ->assertStatus(202);

    // Leaving them behind would fill the challenge directory with one file per
    // click, forever.
    Process::assertRan(fn ($process) => in_array('rm', $process->command, true)
        && collect($process->command)->contains(fn ($part) => str_contains((string) $part, 'acme-challenge/')));
});
