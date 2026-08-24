<?php

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-domains-'.getmypid();
    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.web_server_drivers.apache.sites_dir' => $this->home.'/sites',
        'server.web_server_drivers.openlitespeed.sites_dir' => $this->home.'/sites',
    ]);

    $systemUser = SystemUser::create([
        'username' => 'domuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
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

    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
    ]);

    // Every shell command this feature runs — tee, nginx -t, systemctl reload —
    // succeeds. The point of these tests is what gets written and recorded, not
    // whether the box has nginx on it.
    Process::fake(fn () => Process::result(exitCode: 0));
});

it('lists an applications domains with the primary first', function () {
    $this->application->domains()->create(['domain' => 'aaa.example.com', 'type' => DomainType::Alias]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/domains")
        ->assertOk();

    expect($response->json('domains.0.domain'))->toBe('shop.example.com')
        ->and($response->json('domains.0.type'))->toBe('primary')
        ->and($response->json('domains.1.domain'))->toBe('aaa.example.com');
});

it('adds an alias and rewrites the vhost to serve both names', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", [
            'domain' => 'www.shop.example.com',
            'type' => 'alias',
        ])
        ->assertCreated()
        ->assertJsonPath('domain.type', 'alias')
        ->assertJsonPath('domain.type_title', 'Alias');

    $config = renderedVhost($this->application);

    expect($config)->toContain('shop.example.com')
        ->and($config)->toContain('www.shop.example.com');

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'application',
        'action' => 'domain_added',
    ]);
});

it('gives a redirect its own server block instead of serving the same content', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", [
            'domain' => 'old-shop.example.com',
            'type' => 'redirect',
            'redirect_to' => 'https://shop.example.com',
            'redirect_status' => 301,
        ])
        ->assertCreated();

    $config = renderedVhost($this->application);

    // The redirect name must not join server_name on the content block —
    // that would serve the site under both names rather than redirect one.
    expect($config)->toContain('return 301 https://shop.example.com')
        ->and($config)->not->toMatch('/server_name[^;]*old-shop\.example\.com[^;]*;\s*\n\s*root/');
});

it('refuses a domain already used by another application', function () {
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Blog',
        'slug' => 'blog', 'domain' => 'blog.example.com', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);
    $other->domains()->create(['domain' => 'blog.example.com', 'type' => DomainType::Primary]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => 'blog.example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain');
});

it('rejects hostnames that could break out of the config file', function (string $domain) {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => $domain])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain');
})->with([
    '../../etc/nginx/nginx.conf',
    'evil.com; root /etc',
    'no-dot-here',
    'sp ace.example.com',
]);

it('updates the canonical URL and keeps the old primary as an alias', function () {
    $alias = $this->application->domains()->create([
        'domain' => 'newshop.example.com',
        'type' => DomainType::Alias,
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains/{$alias->id}/primary")
        ->assertOk();

    Process::assertRan(fn ($process) => in_array('option', $process->command, true)
        && in_array('home', $process->command, true)
        && in_array('http://newshop.example.com', $process->command, true));

    expect($this->application->fresh()->domain)->toBe('newshop.example.com')
        ->and($alias->fresh()->type)->toBe(DomainType::Primary)
        // The name it replaced stays attached as an alias rather than being
        // dropped — the site keeps answering on it.
        ->and(ApplicationDomain::whereDomain('shop.example.com')->first()->type)->toBe(DomainType::Alias);

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'application',
        'action' => 'primary_domain_changed',
    ]);
});

it('refuses to remove the primary domain', function () {
    $primary = $this->application->domains()->firstWhere('type', DomainType::Primary->value);

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/domains/{$primary->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain');

    expect($this->application->domains()->count())->toBe(1);
});

it('removes an alias and rewrites the vhost without it', function () {
    $alias = $this->application->domains()->create([
        'domain' => 'extra.example.com', 'type' => DomainType::Alias,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/domains/{$alias->id}")
        ->assertNoContent();

    expect(renderedVhost($this->application->fresh()))->not->toContain('extra.example.com');
});

it('does not let one application address another applications domain', function () {
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Blog',
        'slug' => 'blog', 'domain' => 'blog.example.com', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);
    $foreign = $other->domains()->create(['domain' => 'blog.example.com', 'type' => DomainType::Primary]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/applications/{$this->application->id}/domains/{$foreign->id}")
        ->assertNotFound();
});

it('marks a test domain as not certifiable', function () {
    $domain = $this->application->domains()->create([
        'domain' => 'shop.127-0-0-1.nip.io', 'type' => DomainType::Alias, 'is_test' => true,
    ]);

    // nip.io is not on the Public Suffix List, so every certificate issued for
    // it anywhere on the internet shares one weekly rate limit. Offering the
    // button would spend somebody else's quota.
    expect($domain->certifiable())->toBeFalse();
});

it('needs the app_domain permission to read and manage to write', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_domain');

    $this->actingAs($viewer)
        ->getJson("/api/applications/{$this->application->id}/domains")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => 'nope.example.com'])
        ->assertForbidden();

    $stranger = User::factory()->create();
    grantPermission($stranger, 'app_deployment');

    $this->actingAs($stranger)
        ->getJson("/api/applications/{$this->application->id}/domains")
        ->assertForbidden();
});

it('renders every name on all three web servers', function (string $driver) {
    config(['server.web_server' => $driver]);

    $this->application->domains()->create(['domain' => 'www.shop.example.com', 'type' => DomainType::Alias]);

    $config = renderedVhost($this->application->fresh());

    expect($config)->toContain('shop.example.com')
        ->and($config)->toContain('www.shop.example.com');
})->with(['nginx', 'apache', 'openlitespeed']);

/**
 * What the active driver would write right now, rendered rather than read back
 * from disk — the write itself goes through `tee` under Process::fake.
 */
function renderedVhost(Application $application): string
{
    $driver = app(WebServerManager::class)->driver();

    return $driver->renderConfig(
        $application->fresh(['domains', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($application),
    );
}

it('addresses a domain by hostname as well as by id', function () {
    // The route parameter is called `{domain}` and the model has a `domain`
    // column, so passing the hostname is the obvious reading. It used to 404
    // with "No query results for model [App\Models\ApplicationDomain]
    // alias.example.com" — a message describing the framework's failure to
    // bind rather than anything the caller could act on.
    $domain = $this->application->domains()->create([
        'domain' => 'alias.example.com',
        'type' => DomainType::Alias,
    ]);

    foreach ([$domain->id, $domain->domain, strtoupper($domain->domain)] as $reference) {
        $this->actingAs($this->admin)
            ->postJson("/api/applications/{$this->application->id}/domains/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('domain.domain', 'alias.example.com');
    }
});

it('still refuses a domain belonging to another application', function () {
    // Resolving a name is not authorising access to it — the ownership check
    // has to survive the more forgiving lookup.
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Other site',
        'slug' => 'other-site',
        'domain' => 'other.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    $foreign = $other->domains()->create(['domain' => 'foreign.example.com', 'type' => DomainType::Alias]);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains/{$foreign->domain}/verify")
        ->assertNotFound();
});

it('404s an unknown hostname rather than erroring', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains/nope.example.com/verify")
        ->assertNotFound();
});
