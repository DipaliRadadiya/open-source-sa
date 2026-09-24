<?php

use App\Models\Application;
use App\Models\GitAccount;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * The panel adds the deploy webhook to the repository itself.
 *
 * Deploy-on-push used to hand the user a URL and a secret to paste into
 * GitHub, GitLab or Bitbucket. The panel holds a token for the account the
 * site deploys from, so it now registers the hook, updates it when the secret
 * rotates and removes it when deploy-on-push is switched off or the site is
 * deleted. Anything that stops it falls back to the manual step, with the
 * reason, and never fails the switch.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->systemUser = SystemUser::create([
        'username' => 'gituser', 'home_path' => '/home/gituser', 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    // A panel reachable from the internet. Tests run on `localhost`, which the
    // registrar rightly refuses (see the not-public case below).
    URL::forceRootUrl('https://93.184.216.34');

    Http::preventStrayRequests();
});

function registrationAccount(string $provider = 'github'): GitAccount
{
    return GitAccount::forceCreate([
        'provider' => $provider, 'label' => ucfirst($provider), 'identifier' => 'octo',
        'workspace' => $provider === 'bitbucket' ? 'octo' : null,
        'token' => 'tok_live', 'scopes' => ['repo'], 'last_verified_at' => now(),
    ]);
}

function registrationApp(?GitAccount $account, array $overrides = []): Application
{
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'git', 'serving_profile' => 'php', 'php_version' => '8.4',
        'status' => 'active', 'web_root' => '/',
        'git_account_id' => $account?->id,
        'repository' => 'octo/shop', 'branch' => 'main',
    ], $overrides));
}

function switchRegistration(Application $app, array $body = ['enabled' => true])
{
    return test()->actingAs(test()->admin)->putJson("/api/applications/{$app->id}/webhook", $body);
}

describe('github', function () {
    it('adds a signed, TLS-verified push webhook for the site\'s own URL, and says so', function () {
        Http::fake(['api.github.com/repos/octo/shop/hooks' => Http::response(['id' => 4711], 201)]);
        $app = registrationApp(registrationAccount());

        switchRegistration($app, ['enabled' => true, 'provider' => 'github'])
            ->assertOk()
            ->assertJsonPath('webhook_registration.status', 'registered')
            ->assertJsonPath('webhook_registration.message', null)
            ->assertJsonPath('application.webhook.registered', true);

        $app->refresh();

        expect($app->webhook_remote_id)->toBe('4711');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.github.com/repos/octo/shop/hooks'
            && $r->hasHeader('Authorization', 'Bearer tok_live')
            && $r['events'] === ['push']
            && $r['config']['url'] === $app->webhookUrl()
            && $r['config']['secret'] === $app->webhook_secret
            && $r['config']['insecure_ssl'] === '0');
    });

    it('updates the same hook when the secret is rotated, rather than adding a second', function () {
        Http::fake([
            'api.github.com/repos/octo/shop/hooks' => Http::response(['id' => 4711], 201),
            'api.github.com/repos/octo/shop/hooks/4711' => Http::response(['id' => 4711], 200),
        ]);
        $app = registrationApp(registrationAccount());

        switchRegistration($app, ['enabled' => true, 'provider' => 'github']);
        switchRegistration($app, ['enabled' => true, 'provider' => 'github', 'rotate' => true])->assertOk();

        $app->refresh();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/hooks/4711')
            && $r['config']['secret'] === $app->webhook_secret);
    });

    it('adds it again when somebody deleted it in GitHub', function () {
        Http::fake([
            'api.github.com/repos/octo/shop/hooks/4711' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/octo/shop/hooks' => Http::response(['id' => 5000], 201),
        ]);
        $app = registrationApp(registrationAccount(), ['webhook_remote_id' => '4711', 'webhook_secret' => str_repeat('a', 64), 'webhook_identifier' => 'id-1']);

        switchRegistration($app, ['enabled' => true, 'provider' => 'github'])
            ->assertJsonPath('webhook_registration.status', 'registered');

        expect($app->refresh()->webhook_remote_id)->toBe('5000');
    });

    it('removes the hook it added when deploy-on-push is switched off', function () {
        Http::fake(['api.github.com/repos/octo/shop/hooks/4711' => Http::response(null, 204)]);
        $app = registrationApp(registrationAccount(), ['webhook_enabled' => true, 'webhook_provider' => 'github', 'webhook_remote_id' => '4711']);

        switchRegistration($app, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('webhook_registration', null);

        expect($app->refresh()->webhook_remote_id)->toBeNull();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/4711'));
    });

    it('leaves a hook added by hand alone when switched off', function () {
        Http::fake();
        $app = registrationApp(registrationAccount(), ['webhook_enabled' => true, 'webhook_provider' => 'github']);

        switchRegistration($app, ['enabled' => false])->assertOk();

        Http::assertNothingSent();
    });

    it('keeps the id when the removal fails, so switching back on updates rather than duplicates', function () {
        Http::fake(['api.github.com/*' => Http::response(null, 500)]);
        $app = registrationApp(registrationAccount(), ['webhook_enabled' => true, 'webhook_provider' => 'github', 'webhook_remote_id' => '4711']);

        switchRegistration($app, ['enabled' => false])->assertOk();

        expect($app->refresh()->webhook_enabled)->toBeFalse()
            ->and($app->webhook_remote_id)->toBe('4711');
    });

    it('removes the hook when the site is deleted', function () {
        Http::fake(['api.github.com/repos/octo/shop/hooks/4711' => Http::response(null, 204)]);
        $app = registrationApp(registrationAccount(), ['status' => 'pending', 'webhook_enabled' => true, 'webhook_provider' => 'github', 'webhook_remote_id' => '4711']);

        $this->actingAs($this->admin)->deleteJson("/api/applications/{$app->id}")->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/4711'));
    });
});

describe('falling back to the manual step', function () {
    it('still switches deploy-on-push on, and says why, when the provider refuses', function () {
        // GitHub answers 404 on a repository the token cannot manage hooks on.
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $app = registrationApp(registrationAccount());

        switchRegistration($app, ['enabled' => true, 'provider' => 'github'])
            ->assertOk()
            ->assertJsonPath('application.webhook.enabled', true)
            ->assertJsonPath('application.webhook.registered', false)
            ->assertJsonPath('webhook_registration.status', 'manual')
            ->assertJsonPath('webhook_registration.reason', 'provider_refused')
            ->assertJsonPath('webhook_registration.message', __('application.webhook_registration.provider_refused'));

        expect($app->refresh()->webhook_remote_id)->toBeNull();
    });

    it('does not register a URL the provider could not deliver to', function (string $root) {
        Http::fake();
        URL::forceRootUrl($root);
        $app = registrationApp(registrationAccount());

        switchRegistration($app, ['enabled' => true, 'provider' => 'github'])
            ->assertJsonPath('webhook_registration.reason', 'not_public');

        Http::assertNothingSent();
    })->with(['http://localhost', 'https://10.0.0.5', 'https://127.0.0.1', 'https://192.168.1.20']);

    it('has nothing to act with on a site deployed from a public URL', function () {
        Http::fake();
        $app = registrationApp(null, ['repository' => null, 'repository_url' => 'https://github.com/octo/shop.git']);

        switchRegistration($app, ['enabled' => true, 'provider' => 'github'])
            ->assertJsonPath('webhook_registration.reason', 'no_account');

        Http::assertNothingSent();
    });

    it('leaves a GitLab signing token to the user, since GitLab mints it', function () {
        Http::fake();
        $app = registrationApp(registrationAccount('gitlab'));

        switchRegistration($app, ['enabled' => true, 'provider' => 'gitlab', 'secret' => 'whsec_'.base64_encode(random_bytes(32))])
            ->assertJsonPath('webhook_registration.reason', 'signing_token');

        Http::assertNothingSent();
    });
});

describe('gitlab and bitbucket', function () {
    it('adds a GitLab hook with the plain token GitLab sends back', function () {
        Http::fake(['gitlab.com/api/v4/projects/octo%2Fshop/hooks' => Http::response(['id' => 99], 201)]);
        $app = registrationApp(registrationAccount('gitlab'));

        switchRegistration($app, ['enabled' => true, 'provider' => 'gitlab'])
            ->assertJsonPath('webhook_registration.status', 'registered');

        $app->refresh();

        expect($app->webhook_remote_id)->toBe('99');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['token'] === $app->webhook_secret
            && $r['push_events'] === true
            && $r['enable_ssl_verification'] === true
            && $r['url'] === $app->webhookUrl());
    });

    it('adds a signed Bitbucket hook and keeps its uuid', function () {
        Http::fake(['api.bitbucket.org/2.0/repositories/octo/shop/hooks' => Http::response(['uuid' => '{c0ffee}'], 201)]);
        $app = registrationApp(registrationAccount('bitbucket'));

        switchRegistration($app, ['enabled' => true, 'provider' => 'bitbucket'])
            ->assertJsonPath('webhook_registration.status', 'registered');

        $app->refresh();

        expect($app->webhook_remote_id)->toBe('{c0ffee}');
        Http::assertSent(fn (Request $r) => $r['events'] === ['repo:push'] && $r['secret'] === $app->webhook_secret);
    });
});
