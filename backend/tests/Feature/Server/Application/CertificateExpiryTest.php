<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create([
        'username' => 'expuser', 'home_path' => '/home/expuser', 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop', 'domain' => 'shop.example.com', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);

    $this->application->domains()->create([
        'domain' => 'shop.example.com', 'type' => DomainType::Primary, 'dns_verified_at' => now(),
    ]);

    // Issued sixty days ago and, as far as the panel knows, about to expire.
    $this->certificate = Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/shop.example.com/fullchain.pem',
        'private_key_path' => '/etc/letsencrypt/live/shop.example.com/privkey.pem',
        'issued_at' => now()->subDays(60),
        'expires_at' => now()->addDays(3),
    ]);
});

it('picks up a renewal that happened outside the panel', function () {
    // certbot's timer replaced the file and told nobody. Without this the
    // screen counts down to zero and reports "expired" on a healthy site.
    Process::fake(fn () => Process::result(output: 'notAfter=Dec 31 23:59:59 2030 GMT'));

    $this->artisan('certificates:refresh-expiry')->assertSuccessful();

    expect($this->certificate->fresh()->expires_at?->format('Y'))->toBe('2030')
        ->and($this->certificate->fresh()->status)->toBe(CertificateStatus::Active);
});

it('reports a certificate whose file has gone rather than leaving it active', function () {
    // The vhost still points at the path, so the site is serving something
    // that is not there. Recorded, not repaired — reissuing on a schedule
    // would spend rate limit on a problem nobody has looked at.
    Process::fake(fn () => Process::result(errorOutput: 'No such file or directory', exitCode: 1));

    $this->artisan('certificates:refresh-expiry')->assertSuccessful();

    $certificate = $this->certificate->fresh();

    expect($certificate->status)->toBe(CertificateStatus::Failed)
        ->and($certificate->reason)->toBe('file_missing')
        ->and($certificate->message())->not->toBeNull();
});

it('leaves an unchanged certificate alone', function () {
    $unchanged = $this->certificate->expires_at;

    Process::fake(fn () => Process::result(
        output: 'notAfter='.$unchanged->format('M j H:i:s Y').' GMT'
    ));

    $this->artisan('certificates:refresh-expiry')->assertSuccessful();

    expect($this->certificate->fresh()->updated_at->equalTo($this->certificate->updated_at))->toBeTrue();
});

it('surfaces the refreshed date and the warning flag through the API', function () {
    Process::fake(fn () => Process::result(output: 'notAfter=Dec 31 23:59:59 2030 GMT'));

    $this->artisan('certificates:refresh-expiry');

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/certificate")
        ->assertOk();

    // The warning threshold is computed server-side so the rule lives in one
    // place — certificate lifetimes are shrinking and it will have to move.
    expect($response->json('certificate.expiring_soon'))->toBeFalse()
        ->and($response->json('certificate.expired'))->toBeFalse()
        ->and($response->json('certificate.days_remaining'))->toBeGreaterThan(1000);
});

it('does not touch certificates that are not active', function () {
    $this->certificate->update(['status' => CertificateStatus::Pending]);

    Process::fake(fn () => Process::result(output: 'notAfter=Dec 31 23:59:59 2030 GMT'));

    $this->artisan('certificates:refresh-expiry')->assertSuccessful();

    // A pending certificate has no file behind it yet; reading one would be
    // asking a question that has no answer.
    Process::assertNothingRan();
});

it('repairs a missing renewal hook on a server whose certificates were adopted', function () {
    // Server Sync adopts certificates from a migrated box, and adoption never
    // installed the deploy hook — only issuing through the panel did. Without
    // it certbot renews, the files on disk are current, and the web server goes
    // on serving the old certificate out of memory until something unrelated
    // reloads it. The site shows an expired certificate weeks later.
    Process::fake();

    Certificate::updateOrCreate(['application_id' => $this->application->id], [
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/letsencrypt/live/shop.example.com/fullchain.pem',
    ]);

    $this->artisan('certificates:refresh-expiry')->assertSuccessful();

    // Daily and idempotent, so every server already in this state repairs
    // itself rather than only the ones adopted from now on.
    Process::assertRan(fn ($p) => in_array('tee', $p->command, true)
        && str_contains(implode(' ', $p->command), 'renewal-hooks'));
});

it('writes no hook when nothing on the server renews', function () {
    // An uploaded certificate is not certbot's to renew, so a privileged write
    // here would be a command run for no reason on every tick.
    Process::fake();

    Certificate::updateOrCreate(['application_id' => $this->application->id], [
        'type' => CertificateType::Custom,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/ssl/shop/fullchain.pem',
    ]);

    $this->artisan('certificates:refresh-expiry')->assertSuccessful();

    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), 'renewal-hooks'));
});
