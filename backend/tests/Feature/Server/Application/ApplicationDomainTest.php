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

    // Every shell command (tee, nginx -t, systemctl reload) succeeds: the tests check what is
    // written and recorded, not whether nginx exists.
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

    // The redirect name must not join server_name on the content block, or the site would be
    // served under both names.
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
        // The replaced name stays attached as an alias, so the site keeps answering on it.
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

    // nip.io is not on the Public Suffix List: all its certificates share one weekly rate limit.
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
    // The route parameter is `{domain}` and the model has a `domain` column, so the hostname
    // must resolve too (it used to 404 with a model-binding error).
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
    // Resolving a name is not authorising access: the ownership check must still apply.
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

/*
|--------------------------------------------------------------------------
| Editing what a name does
|--------------------------------------------------------------------------
|
| Detach + re-add would leave a gap where the vhost lacks the name, and a failed re-add
| leaves the name off a site whose certificate still covers it (renewal then fails).
| {@see Certificate::staleDomains()}
*/

it('fixes a mistyped redirect target without detaching the name', function () {
    $domain = $this->application->domains()->create([
        'domain' => 'www.example.com',
        'type' => DomainType::Redirect,
        'redirect_to' => 'https://exmaple.com',
        'redirect_status' => 301,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", [
            'redirect_to' => 'https://example.com',
        ])
        ->assertOk()
        ->assertJsonPath('domain.redirect_to', 'https://example.com');

    // Still attached, and still the same row.
    expect($domain->fresh()->id)->toBe($domain->id)
        ->and($this->application->domains()->count())->toBe(2)
        // The server block was rewritten, or the old target is still live.
        ->and(renderedVhost($this->application))->toContain('https://example.com')
        ->and(renderedVhost($this->application))->not->toContain('exmaple');
});

it('turns a redirect into an alias and drops the target it no longer has', function () {
    $domain = $this->application->domains()->create([
        'domain' => 'www.example.com',
        'type' => DomainType::Redirect,
        'redirect_to' => 'https://example.com',
        'redirect_status' => 301,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", ['type' => 'alias'])
        ->assertOk()
        ->assertJsonPath('domain.type', 'alias')
        // An alias serves the site itself: a leftover target would be shown but never used.
        ->assertJsonPath('domain.redirect_to', null)
        ->assertJsonPath('domain.redirect_status', null);

    expect(renderedVhost($this->application))->not->toContain('https://example.com');
});

it('reports a redirect status only on a redirect', function () {
    $this->application->domains()->create([
        'domain' => 'old.example.com',
        'type' => DomainType::Redirect,
        'redirect_to' => 'https://example.com',
        'redirect_status' => 302,
    ]);
    $this->application->domains()->create(['domain' => 'www.example.com', 'type' => DomainType::Alias]);

    $domains = collect($this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/domains")
        ->assertOk()
        ->json('domains'))->keyBy('domain');

    // The column defaults to 301, so an alias or primary must not report a redirect code.
    expect($domains['old.example.com']['redirect_status'])->toBe(302)
        ->and($domains['www.example.com']['redirect_status'])->toBeNull()
        ->and($domains['shop.example.com']['redirect_status'])->toBeNull();
});

it('refuses to make a name a redirect with nowhere to redirect to', function () {
    // `required_if` cannot catch this: the payload has a type but no target, and the stored
    // alias row has none either.
    $domain = $this->application->domains()->create([
        'domain' => 'www.example.com',
        'type' => DomainType::Alias,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", ['type' => 'redirect'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('redirect_to');

    expect($domain->fresh()->type)->toBe(DomainType::Alias);
});

it('refuses to edit the primary domain', function () {
    // The primary names the vhost file and both log files: changing it is `makePrimary()`'s job.
    $primary = $this->application->domains()->where('type', DomainType::Primary)->firstOrFail();

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$primary->id}", ['type' => 'alias'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');

    expect($primary->fresh()->type)->toBe(DomainType::Primary);
});

it('cannot rename a domain, because the certificate would keep the old name', function () {
    // A rename leaves the old name in the certificate's lineage; `certbot renew` re-validates
    // every name and fails the whole renewal when one cannot be validated.
    $domain = $this->application->domains()->create([
        'domain' => 'www.example.com',
        'type' => DomainType::Alias,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", [
            'domain' => 'renamed.example.com',
        ])
        ->assertOk();

    expect($domain->fresh()->domain)->toBe('www.example.com');
});

it('does not let one application edit another applications domain', function () {
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);
    $theirs = $other->domains()->create(['domain' => 'alias.example.com', 'type' => DomainType::Alias]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$theirs->id}", ['type' => 'redirect', 'redirect_to' => 'https://example.com'])
        ->assertNotFound();

    expect($theirs->fresh()->type)->toBe(DomainType::Alias);
});

it('needs manage permission to edit a domain', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_domain');

    $domain = $this->application->domains()->create(['domain' => 'www.example.com', 'type' => DomainType::Alias]);

    $this->actingAs($viewer)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", ['type' => 'redirect', 'redirect_to' => 'https://example.com'])
        ->assertForbidden();
});

it('names the application already holding a domain', function () {
    // The name is unique across the whole server, so the error must say which site holds it.
    $other = Application::forceCreate([
        'system_user_id' => $this->application->system_user_id,
        'name' => 'Company Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);
    $other->domains()->create(['domain' => 'blog.example.com', 'type' => DomainType::Primary]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => 'blog.example.com'])
        ->assertStatus(422);

    expect($response->json('errors.domain.0'))->toContain('Company Blog');
});

it('refuses a redirect target that would write config into the vhost', function (string $target) {
    // Every one of these passes Laravel's `url` rule, but `;` ends nginx's `return` and `#`
    // comments out the rest, so the vhost would gain a directive of the user's choosing.
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", [
            'domain' => 'old-shop.example.com', 'type' => 'redirect', 'redirect_to' => $target,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['redirect_to' => __('errors/application.redirect_target_invalid')]);

    $domain = $this->application->domains()->create([
        'domain' => 'www.example.com', 'type' => DomainType::Redirect,
        'redirect_to' => 'https://example.com', 'redirect_status' => 301,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", ['redirect_to' => $target])
        ->assertStatus(422)
        ->assertJsonValidationErrors('redirect_to');

    expect(renderedVhost($this->application))->not->toContain('internal');
})->with([
    'directive' => 'https://example.com/;internal;#',
    'nginx variable' => 'https://example.com/$host',
    'query string' => 'https://example.com/?a=1&b=2',
    'no scheme' => 'example.com/;x',
]);

it('still takes an ordinary redirect target with a path', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", [
            'domain' => 'old-shop.example.com', 'type' => 'redirect',
            'redirect_to' => 'https://shop.example.com:8443/new-home_page.v2/~x',
        ])
        ->assertCreated();
});

it('refuses the hostnames the panel itself is served on', function (string $domain) {
    // install.sh writes these as API_HOST and PANEL_HOST. A site holding one fights the panel's
    // vhost for the name and shares the panel's certificate lineage.
    config(['app.url' => 'https://api.panel.example.org', 'server.storage.panel_url' => 'https://panel.example.org']);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => $domain])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['domain' => __('errors/application.domain_is_panel')]);

    $this->actingAs($this->admin)
        ->postJson('/api/applications', [
            'site_type' => 'php', 'name' => 'Grab', 'domain' => $domain,
            'system_user_id' => $this->application->system_user_id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('domain');
})->with(['panel.example.org', 'API.panel.example.org']);

/*
 * Create refuses IPs, so Add domain must too: the server's own IP would serve this site
 * instead of the default 404.
 */
it('refuses an IP address as a domain', function (string $domain) {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => $domain])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['domain' => __('errors/application.domain_is_ip')]);
})->with(['23.172.120.86', '127.0.0.1', '10.0.0.1']);

it('still takes a domain whose labels are mostly digits', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => '123.456.example.com'])
        ->assertCreated();
});

it('still takes a sibling of the panel host', function () {
    config(['app.url' => 'https://panel.example.org', 'server.storage.panel_url' => 'https://panel.example.org']);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", ['domain' => 'shop.panel.example.org'])
        ->assertCreated();
});

/*
| Every template appends the request path to the target, and the path brings its own `/`:
| `https://new.example/` would redirect to `https://new.example//page`.
*/
it('saves a redirect target without its trailing slash', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/domains", [
            'domain' => 'old-shop.example.com', 'type' => 'redirect',
            'redirect_to' => 'https://new.example.com/blog//', 'redirect_status' => 301,
        ])
        ->assertCreated()
        ->assertJsonPath('domain.redirect_to', 'https://new.example.com/blog');

    $domain = ApplicationDomain::where('domain', 'old-shop.example.com')->sole();

    $this->actingAs($this->admin)
        ->putJson("/api/applications/{$this->application->id}/domains/{$domain->id}", ['redirect_to' => 'https://new.example.com/'])
        ->assertOk()
        ->assertJsonPath('domain.redirect_to', 'https://new.example.com');
});

it('never writes a double slash into the redirect, on any web server', function (string $driver, string $expected) {
    // A row saved earlier still carries the slash; the vhost must not.
    $this->application->domains()->create([
        'domain' => 'old-shop.example.com', 'type' => DomainType::Redirect,
        'redirect_to' => 'https://new.example.com/', 'redirect_status' => 301,
    ]);
    config(['server.web_server' => $driver]);

    $config = app((string) config("server.web_server_drivers.{$driver}.driver"))->renderConfig(
        $this->application->fresh(['domains', 'systemUser']),
        app(ApplicationProvisioner::class)->documentRoot($this->application),
    );

    expect($config)->toContain($expected)
        ->not->toContain('https://new.example.com//')
        ->not->toContain('https://new.example.com/$request_uri');
})->with([
    'nginx' => ['nginx', 'return 301 https://new.example.com$request_uri;'],
    'apache' => ['apache', 'https://new.example.com/$1'],
    'openlitespeed' => ['openlitespeed', 'RewriteRule ^/?(.*)$ https://new.example.com/$1 [R=301,L]'],
]);
