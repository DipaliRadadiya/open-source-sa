<?php

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * What certificate this site can actually have.
 *
 * A self-signed certificate has always worked for a name Let's Encrypt cannot
 * validate — `RequestCertificate` routes it past every reachability check —
 * but nothing in the API said so, so the screen could only offer the option
 * that was going to fail and call the site un-securable.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
    Queue::fake();
});

function certificateOptions(): Collection
{
    return collect(test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/applications/'.test()->application->id.'/certificate')
        ->assertOk()
        ->json('available_types'))
        ->keyBy('type');
}

it('offers Let\'s Encrypt and recommends it once a domain is verified', function () {
    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
        'is_test' => false,
        'dns_verified_at' => now(),
    ]);

    $options = certificateOptions();

    expect($options['letsencrypt']['available'])->toBeTrue()
        ->and($options['letsencrypt']['recommended'])->toBeTrue()
        ->and($options['letsencrypt']['reason'])->toBeNull()
        // Still offered, just not the one to reach for.
        ->and($options['self_signed']['available'])->toBeTrue()
        ->and($options['self_signed']['recommended'])->toBeFalse();
});

it('recommends a self-signed certificate for a site that only has a test domain', function () {
    $this->application->domains()->create([
        'domain' => 'shop.203.0.113.10.nip.io',
        'type' => DomainType::Primary,
        'is_test' => true,
    ]);

    $options = certificateOptions();

    expect($options['letsencrypt']['available'])->toBeFalse()
        // The point of the whole change: the site is not un-securable, it
        // just cannot have *that* certificate.
        ->and($options['self_signed']['available'])->toBeTrue()
        ->and($options['self_signed']['recommended'])->toBeTrue();

    // And the refusal names the domain and says what to do instead, rather
    // than leaving the user to guess which of their domains is the problem.
    expect($options['letsencrypt']['reason'])
        ->toContain('shop.203.0.113.10.nip.io')
        ->and($options['self_signed']['reason'])->not->toBeEmpty();
});

it('tells a site with unpointed DNS to fix DNS, not that its domain is temporary', function () {
    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
        'is_test' => false,
        'dns_verified_at' => null,
    ]);

    $reason = certificateOptions()['letsencrypt']['reason'];

    // Two different problems with two different fixes — one is "wait for DNS",
    // the other is "nothing will ever work here, take the self-signed one".
    expect($reason)->toBe(__('certificate.unavailable.dns_unverified'))
        ->and($reason)->not->toBe(__('certificate.unavailable.test_domain', ['domains' => 'shop.example.com']));
});

it('does not call a mixed site test-only when one real domain is still pending', function () {
    $this->application->domains()->create([
        'domain' => 'shop.203.0.113.10.nip.io', 'type' => DomainType::Primary, 'is_test' => true,
    ]);
    $this->application->domains()->create([
        'domain' => 'shop.example.com', 'type' => DomainType::Alias, 'is_test' => false, 'dns_verified_at' => null,
    ]);

    // The real domain can still be pointed here, so "your domains are
    // temporary" would be wrong advice — there is something to wait for.
    expect(certificateOptions()['letsencrypt']['reason'])
        ->toBe(__('certificate.unavailable.dns_unverified'));
});

it('translates the reason', function () {
    $this->application->domains()->create([
        'domain' => 'shop.203.0.113.10.nip.io', 'type' => DomainType::Primary, 'is_test' => true,
    ]);

    // English first: the locale set by the request below outlives it within
    // one test, so asking afterwards compares French against French.
    $english = certificateOptions()['letsencrypt']['reason'];

    $french = collect($this->withHeaders([
        'Authorization' => "Bearer {$this->token}",
        'Accept-Language' => 'fr',
    ])->getJson('/api/applications/'.$this->application->id.'/certificate')
        ->json('available_types'))->keyBy('type');

    expect($french['letsencrypt']['reason'])->not->toBe($english)
        ->and($french['letsencrypt']['reason'])->not->toBeEmpty();
});

it('still lets a test domain actually get the self-signed certificate it is offered', function () {
    // The offer above is worth nothing if the request behind it is refused —
    // this is the end of the path the panel is now pointing users down.
    $this->application->domains()->create([
        'domain' => 'shop.203.0.113.10.nip.io', 'type' => DomainType::Primary, 'is_test' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/applications/'.$this->application->id.'/certificate', [
            'type' => 'self_signed',
        ])
        ->assertStatus(202);
});
