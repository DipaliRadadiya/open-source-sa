<?php

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\GitAccount;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->account = GitAccount::create([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat',
        'token' => 'ghp_super_secret_value',
    ]);

    Queue::fake();
});

function hookHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function hookApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop '.Str::random(6),
        'domain' => 'shop.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
        'git_account_id' => test()->account->id,
        'repository' => 'octocat/hello',
        'branch' => 'main',
        'rendering_type' => 'php',
    ], $overrides));
}

/**
 * An application with deploy-on-push already configured, bypassing the API so a
 * delivery test is not also a test of the configure endpoint.
 */
function hookedApp(string $provider = 'github', string $secret = 'a-secret-of-sufficient-length', array $overrides = []): Application
{
    $app = hookApp($overrides);

    $app->forceFill([
        'webhook_enabled' => true,
        'webhook_provider' => $provider,
        'webhook_identifier' => 'wh-'.$provider.'-'.$app->id,
        'webhook_secret' => $secret,
    ])->save();

    return $app->refresh();
}

function pushBody(string $branch = 'main'): string
{
    return json_encode(['ref' => "refs/heads/{$branch}"], JSON_THROW_ON_ERROR);
}

/**
 * Posts a raw body, which is the whole point: the signature covers the exact
 * bytes, so `postJson` with an array — which re-encodes — would sign one thing
 * and send another.
 */
function deliver(Application $app, string $body, array $headers): TestResponse
{
    return test()->call(
        'POST',
        "/api/webhooks/deploy/{$app->webhook_identifier}",
        [], [], [], collect($headers)->mapWithKeys(
            fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v]
        )->put('CONTENT_TYPE', 'application/json')->all(),
        $body,
    );
}

describe('GitHub', function () {
    it('deploys on a signed push to the tracked branch', function () {
        $app = hookedApp('github');
        $body = pushBody();

        deliver($app, $body, [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'delivery-1',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202)->assertJson(['deployed' => true]);

        Queue::assertPushed(DeployApplication::class);
        expect($app->refresh()->webhook_last_delivered_at)->not->toBeNull();
    });

    it('refuses a body that was changed after signing', function () {
        $app = hookedApp('github');
        $signature = 'sha256='.hash_hmac('sha256', pushBody(), (string) $app->webhook_secret);

        deliver($app, pushBody('other'), [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
        ])->assertStatus(401)->assertJson(['deployed' => false, 'reason' => 'invalid_signature']);

        Queue::assertNothingPushed();
    });

    it('refuses a delivery with no signature at all', function () {
        $app = hookedApp('github');

        deliver($app, pushBody(), ['X-GitHub-Event' => 'push'])->assertStatus(401);

        Queue::assertNothingPushed();
    });

    it('rejects Bitbucket header on a GitHub application', function () {
        // The trap this feature is built around: Bitbucket signs identically but
        // calls the header `X-Hub-Signature`, GitHub's name minus the suffix.
        // A verifier reading the wrong one would accept nothing, or — worse, if
        // the scheme were chosen from the request — the weakest thing offered.
        $app = hookedApp('github');
        $body = pushBody();

        deliver($app, $body, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(401);

        Queue::assertNothingPushed();
    });

    it('accepts but ignores a push to another branch', function () {
        // 2xx on purpose: GitHub and GitLab disable a hook that keeps failing,
        // and "not my branch" is not a failure.
        $app = hookedApp('github');
        $body = pushBody('feature/x');

        deliver($app, $body, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202)->assertJson(['deployed' => false, 'reason' => 'other_branch']);

        Queue::assertNothingPushed();
    });

    it('accepts but ignores a tag push', function () {
        $app = hookedApp('github');
        // refs/tags/main — the same name as the branch, which is exactly the
        // case a naive "last path segment" parse would deploy.
        $body = json_encode(['ref' => 'refs/tags/main']);

        deliver($app, $body, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202)->assertJson(['reason' => 'other_branch']);

        Queue::assertNothingPushed();
    });

    it('accepts but ignores an event that is not a push', function () {
        $app = hookedApp('github');
        $body = pushBody();

        deliver($app, $body, [
            'X-GitHub-Event' => 'issue_comment',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202)->assertJson(['reason' => 'not_a_push']);

        Queue::assertNothingPushed();
    });

    it('deploys once when the provider retries the same delivery', function () {
        // Providers retry on a timeout, and the first attempt may well have
        // worked. Two deploys from one push is a real cost, not a cosmetic one.
        $app = hookedApp('github');
        $body = pushBody();
        $headers = [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'the-same-delivery',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ];

        deliver($app, $body, $headers)->assertJson(['deployed' => true]);
        deliver($app, $body, $headers)->assertStatus(202)->assertJson(['reason' => 'duplicate_delivery']);

        Queue::assertPushed(DeployApplication::class, 1);
    });
});

describe('Bitbucket', function () {
    it('deploys on a signed push, reading the branch out of push.changes', function () {
        $app = hookedApp('bitbucket');
        $body = json_encode(['push' => ['changes' => [
            ['new' => ['type' => 'branch', 'name' => 'main']],
        ]]]);

        deliver($app, $body, [
            'X-Event-Key' => 'repo:push',
            'X-Request-UUID' => 'bb-1',
            'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202)->assertJson(['deployed' => true]);

        Queue::assertPushed(DeployApplication::class);
    });

    it('ignores a branch deletion and a tag', function () {
        $app = hookedApp('bitbucket');
        $body = json_encode(['push' => ['changes' => [
            ['new' => null],
            ['new' => ['type' => 'tag', 'name' => 'main']],
        ]]]);

        deliver($app, $body, [
            'X-Event-Key' => 'repo:push',
            'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202)->assertJson(['reason' => 'other_branch']);

        Queue::assertNothingPushed();
    });
});

describe('GitLab', function () {
    it('deploys on a valid plaintext secret token', function () {
        $app = hookedApp('gitlab', 'a-plain-secret-token-value');

        deliver($app, pushBody(), [
            'X-Gitlab-Event' => 'Push Hook',
            'X-Gitlab-Event-UUID' => 'gl-1',
            'X-Gitlab-Token' => (string) $app->webhook_secret,
        ])->assertStatus(202)->assertJson(['deployed' => true]);

        Queue::assertPushed(DeployApplication::class);
    });

    it('refuses a wrong plaintext token', function () {
        $app = hookedApp('gitlab', 'a-plain-secret-token-value');

        deliver($app, pushBody(), [
            'X-Gitlab-Event' => 'Push Hook',
            'X-Gitlab-Token' => 'not-the-token',
        ])->assertStatus(401);

        Queue::assertNothingPushed();
    });

    it('deploys on a signing token, over id.timestamp.body', function () {
        // GitLab's recommended mode, per the Standard Webhooks spec. The signed
        // content is not the body alone — signing only the body is the mistake
        // this test exists to catch.
        $key = random_bytes(24);
        $app = hookedApp('gitlab', 'whsec_'.base64_encode($key));

        $id = 'msg-1';
        $timestamp = (string) now()->getTimestamp();
        $body = pushBody();

        deliver($app, $body, [
            'X-Gitlab-Event' => 'Push Hook',
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true)),
        ])->assertStatus(202)->assertJson(['deployed' => true]);

        Queue::assertPushed(DeployApplication::class);
    });

    it('accepts one signature out of a list', function () {
        // GitLab sends one today and says that may change.
        $key = random_bytes(24);
        $app = hookedApp('gitlab', 'whsec_'.base64_encode($key));
        $id = 'msg-2';
        $timestamp = (string) now()->getTimestamp();
        $body = pushBody();
        $good = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        deliver($app, $body, [
            'X-Gitlab-Event' => 'Push Hook',
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => "v1,AAAA {$good}",
        ])->assertStatus(202)->assertJson(['deployed' => true]);
    });

    it('refuses a correctly signed delivery that is too old', function () {
        // A captured delivery stays validly signed forever; the timestamp
        // window is the only thing that stops it being replayable forever.
        $key = random_bytes(24);
        $app = hookedApp('gitlab', 'whsec_'.base64_encode($key));

        $id = 'msg-3';
        $timestamp = (string) now()->subHour()->getTimestamp();
        $body = pushBody();

        deliver($app, $body, [
            'X-Gitlab-Event' => 'Push Hook',
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true)),
        ])->assertStatus(401);

        Queue::assertNothingPushed();
    });

    it('refuses a signing token that is not valid base64', function () {
        $app = hookedApp('gitlab', 'whsec_!!!not-base64!!!');
        $id = 'msg-4';
        $timestamp = (string) now()->getTimestamp();

        deliver($app, pushBody(), [
            'X-Gitlab-Event' => 'Push Hook',
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,anything',
        ])->assertStatus(401);
    });
});

describe('one deploy per burst', function () {
    it('is unique per application until it starts processing', function () {
        // Asserted directly because `Queue::fake()` never applies unique-job
        // locking — a test that only counted pushed jobs would pass whether or
        // not the interface was there.
        $app = hookApp();
        $other = hookApp(['domain' => 'other.example.com']);

        expect(new DeployApplication($app->id))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
        expect((new DeployApplication($app->id))->uniqueId())->toBe((string) $app->id);
        // Two applications must not share a lock, or a push to one would swallow
        // the other's deploy.
        expect((new DeployApplication($app->id))->uniqueId())
            ->not->toBe((new DeployApplication($other->id))->uniqueId());
    });
});

describe('the endpoint itself', function () {
    it('is reachable without authentication', function () {
        // The signature is the credential. If this ever needs a token, no
        // provider can call it.
        $app = hookedApp('github');
        $body = pushBody();

        deliver($app, $body, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret),
        ])->assertStatus(202);
    });

    it('is not bound by the 20-per-minute guest IP limit', function () {
        // `throttle:api` is prepended to every API route and caps guests at
        // 20/minute per IP. A provider delivers from shared egress, so that
        // limit would drop real deliveries and look like a hook that
        // "sometimes doesn't fire". This route opts out and is limited per
        // webhook instead.
        $app = hookedApp('github');
        $body = pushBody('untracked');
        $signature = 'sha256='.hash_hmac('sha256', $body, (string) $app->webhook_secret);

        foreach (range(1, 25) as $i) {
            deliver($app, $body, [
                'X-GitHub-Event' => 'push',
                'X-GitHub-Delivery' => "d-{$i}",
                'X-Hub-Signature-256' => $signature,
            ])->assertStatus(202);
        }
    });

    it('answers the same for a disabled webhook and one that never existed', function () {
        $app = hookedApp('github');
        $app->forceFill(['webhook_enabled' => false])->save();

        $disabled = deliver($app, pushBody(), ['X-GitHub-Event' => 'push']);
        $unknown = test()->call('POST', '/api/webhooks/deploy/no-such-identifier', [], [], [], [], pushBody());

        expect($disabled->status())->toBe(404);
        expect($unknown->status())->toBe(404);
    });
});

describe('configuring it', function () {
    it('generates a URL and a secret for GitHub', function () {
        $app = hookApp();

        $body = $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->assertOk()->json('application.webhook');

        expect($body['enabled'])->toBeTrue();
        expect($body['url'])->toContain('/api/webhooks/deploy/');
        expect(strlen((string) $body['secret']))->toBe(64);
    });

    it('says so when GitLab falls back to the plaintext scheme', function () {
        // With no secret supplied, a legacy plaintext token is the only thing
        // the panel can generate for GitLab — GitLab mints the signing token
        // itself. That is allowed, but it must not be silent: the response says
        // which check is in force so the UI can offer the stronger one.
        $app = hookApp();

        $webhook = $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'gitlab'])
            ->assertOk()->json('application.webhook');

        expect($webhook['verification'])->toBe('token');
    });

    it('reports a pasted GitLab signing token as signature-verified', function () {
        $app = hookApp();

        $webhook = $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", [
                'enabled' => true, 'provider' => 'gitlab', 'secret' => 'whsec_'.base64_encode(random_bytes(24)),
            ])->assertOk()->json('application.webhook');

        expect($webhook['verification'])->toBe('signature');
    });

    it('keeps the URL and secret across a disable and re-enable', function () {
        // Otherwise turning it off and on again silently invalidates what the
        // user pasted into their repository settings.
        $app = hookApp();
        $headers = hookHeaders();

        $first = $this->withHeaders($headers)
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->json('application.webhook');

        $this->withHeaders($headers)->putJson("/api/applications/{$app->id}/webhook", ['enabled' => false])->assertOk();

        $again = $this->withHeaders($headers)
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->json('application.webhook');

        expect($again['url'])->toBe($first['url']);
        expect($again['secret'])->toBe($first['secret']);
    });

    it('records enabling as enabling, not as a rotation', function () {
        // Enabling for the first time has no previous secret to replace.
        // Reporting it as "replaced the secret" tells the user something was
        // invalidated when nothing was.
        $app = hookApp();

        $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->assertOk();

        expect(DB::table('activity_logs')->where('type', 'application')->pluck('action')->all())
            ->toContain('webhook_enabled')
            ->not->toContain('webhook_rotated');
    });

    it('replaces the secret when asked to rotate, keeping the URL', function () {
        $app = hookApp();
        $headers = hookHeaders();

        $first = $this->withHeaders($headers)
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->json('application.webhook');

        $rotated = $this->withHeaders($headers)
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github', 'rotate' => true])
            ->json('application.webhook');

        expect($rotated['secret'])->not->toBe($first['secret']);
        expect($rotated['url'])->toBe($first['url']);
    });

    it('refuses a short secret', function () {
        $app = hookApp();

        $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", [
                'enabled' => true, 'provider' => 'github', 'secret' => 'short',
            ])->assertStatus(422)->assertJsonValidationErrors('secret');
    });

    it('is refused for an application that is not deployed from git', function () {
        $app = hookApp(['site_type' => 'wordpress', 'domain' => 'blog.example.com']);

        // 404 rather than the action's own 422: now that this route is gated by
        // `app_deployment`, CheckPermission's site-type check runs first and
        // answers the way every other per-app screen does — for a WordPress
        // site the Deployment screen does not exist, which is a different
        // statement from "your request was malformed".
        $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->assertStatus(404);
    });

    it('is configurable by whoever owns the Deployment screen', function () {
        $deployer = User::factory()->create();
        grantPermission($deployer, 'app_deployment', view: true, manage: true);
        $app = hookApp();

        // It configures the Deployment screen, so the grant that owns that
        // screen is the one that should carry it. Gating this on the
        // server-level `application` permission meant the owner of the screen
        // could not set up its webhook, while someone who could not see the
        // screen at all could.
        $this->withHeaders(['Authorization' => 'Bearer '.$deployer->createToken('t')->plainTextToken])
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->assertOk();
    });

    it('needs manage permission, not just view', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'application');
        $app = hookApp();

        $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->assertForbidden();
    });

    it('stores the secret encrypted', function () {
        $app = hookApp();

        $secret = $this->withHeaders(hookHeaders())
            ->putJson("/api/applications/{$app->id}/webhook", ['enabled' => true, 'provider' => 'github'])
            ->json('application.webhook.secret');

        $stored = (string) DB::table('applications')->where('id', $app->id)->value('webhook_secret');

        expect($stored)->not->toBe($secret);
        expect($stored)->not->toContain($secret);
    });

    it('describes each provider and which way its secret travels', function () {
        $providers = collect($this->withHeaders(hookHeaders())
            ->getJson('/api/webhook-providers')->assertOk()->json('webhook_providers'))
            ->keyBy('name');

        expect($providers['github']['secret_source'])->toBe('generate');
        expect($providers['bitbucket']['secret_source'])->toBe('generate');
        // GitLab is both, and the two are not equivalent — see GitlabWebhook.
        expect($providers['gitlab']['secret_source'])->toBe('either');
        expect($providers['gitlab']['instructions'])->not->toBeEmpty();
    });
});
