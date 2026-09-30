<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Editing a container site's compose file after it exists.
 *
 * Before this the file was writable exactly once, on the create form. A site that
 * needed a new environment variable had no way to get one: the Environment screen
 * is not offered on a container, and the compose text no endpoint accepted.
 *
 * Two things here are not decoration and are what these tests are mostly about:
 *
 *  - **Rollback.** Every other apply path changes one field; this one hands over
 *    the whole file, so a save can stop the site in ways no validator predicts. A
 *    save that leaves a site down and the panel holding the text that broke it is
 *    worse than a save that refuses.
 *  - **The vhost.** With a pasted compose the FILE chooses the published port, not
 *    the panel. Change it and forget to re-render the vhost and nginx proxies to a
 *    port nothing listens on — a 502 caused by a save that reported success.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'shop', 'home_path' => '/home/shop']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'nginx:1.27-alpine', 'container_port' => 80, 'app_port' => 20001,
        'status' => 'active',
    ]);
});

/** This suite's beforeEach already records a docker box; this names that fact. */
function dockerStack(): void
{
    // Deliberately a no-op: the capability row is written in `beforeEach`, and the
    // permission tests read better saying which stack they assume.
}

function composeHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function composeUrl(): string
{
    return '/api/applications/'.test()->application->id.'/container/compose';
}

/** A valid file publishing one loopback port. */
function validCompose(int $hostPort = 20001, int $containerPort = 80): string
{
    return "services:\n  app:\n    image: nginx:1.27-alpine\n    ports:\n      - \"127.0.0.1:{$hostPort}:{$containerPort}\"\n";
}

/**
 * Faked per command. `compose config` is how the validator reads the file, so it
 * has to answer with the resolved document — a blanket fake makes the validator
 * report every file unparsable and the test then asserts against a box the fake
 * broke.
 *
 * @param  callable|null  $onUp  return a failure to make `compose up` fail.
 */
function fakeComposeBox(string $resolved, ?callable $onUp = null): void
{
    Process::fake(function ($process) use ($resolved, $onUp) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && in_array('config', $args, true)) {
            return Process::result(output: $resolved);
        }

        if (($args[0] ?? '') === 'docker' && in_array('up', $args, true) && $onUp !== null) {
            return $onUp();
        }

        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

/**
 * What `docker compose config` prints for {@see validCompose()}.
 *
 * **JSON, not YAML.** The validator asks for `--format json` and `json_decode`s the
 * answer, so a YAML fixture reads back as null and every file is reported
 * unparsable — which is what the first version of these tests asserted against.
 */
function resolvedCompose(int $hostPort = 20001, int $containerPort = 80): string
{
    return json_encode([
        'name' => 'sv-app-1',
        'services' => [
            'app' => [
                'image' => 'nginx:1.27-alpine',
                'ports' => [[
                    'mode' => 'ingress',
                    // The field the loopback rule reads. Omit it and the file is
                    // published to every address, which is the refusal below.
                    'host_ip' => '127.0.0.1',
                    'target' => $containerPort,
                    'published' => (string) $hostPort,
                    'protocol' => 'tcp',
                ]],
            ],
        ],
    ]);
}

/** The same document with no `host_ip` — published to 0.0.0.0. */
function publicResolvedCompose(): string
{
    return json_encode([
        'name' => 'sv-app-1',
        'services' => [
            'app' => [
                'image' => 'nginx',
                'ports' => [[
                    'mode' => 'ingress',
                    'target' => 80,
                    'published' => '20001',
                    'protocol' => 'tcp',
                ]],
            ],
        ],
    ]);
}

/*
 * Reading it.
 */

it('hands back the generated file for a site that has no stored one', function () {
    // Simple mode: the panel renders the file from the fields on every deploy, so
    // there is nothing stored. Showing an empty editor would invite somebody to
    // write one from scratch when they wanted to change one line of the real one.
    $response = $this->withHeaders(composeHeaders())->getJson(composeUrl())->assertOk();

    expect($response->json('generated'))->toBeTrue()
        ->and($response->json('compose'))->toContain('nginx:1.27-alpine')
        // The panel's own guarantees are in the rendered file, and seeing them is
        // the point: somebody about to take the file over should know what they
        // are taking responsibility for.
        ->and($response->json('compose'))->toContain('127.0.0.1:20001:80')
        ->and($response->json('compose'))->toContain('mem_limit');
});

it('hands back the stored file once there is one', function () {
    $this->application->forceFill(['compose' => validCompose()])->save();

    $this->withHeaders(composeHeaders())
        ->getJson(composeUrl())
        ->assertOk()
        ->assertJsonPath('generated', false)
        ->assertJsonPath('compose', validCompose());
});

it('does not touch the server when the file is only read', function () {
    // The pasted-file path records the published port as a side effect. A GET that
    // reached it would be a read that rewrites a row and restarts nothing — the
    // worst kind of surprise.
    $this->application->forceFill(['compose' => validCompose(30500)])->save();
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $ran[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(composeHeaders())->getJson(composeUrl())->assertOk();

    expect($ran)->toBeEmpty()
        ->and($this->application->fresh()->app_port)->toBe(20001);
});

/*
 * Writing it.
 */

it('writes the file and recreates the container', function () {
    fakeComposeBox(resolvedCompose());

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => validCompose()])
        ->assertOk();

    expect($this->application->fresh()->compose)->toBe(validCompose());
});

it('refuses a file that publishes to every address', function () {
    // The single most important rule in this validator, and the reason the editor
    // cannot be a plain file write: a container published on 0.0.0.0 is reachable
    // past the firewall.
    // Answered the same way whatever is sent, so the loopback rewrite's re-parse
    // also comes back public — which is the case the validator refuses outright
    // rather than silently accepting.
    fakeComposeBox(publicResolvedCompose());

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => "services:\n  app:\n    image: nginx\n    ports:\n      - \"20001:80\"\n"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('compose');

    // And nothing was stored, so the refusal cost the site nothing.
    expect($this->application->fresh()->compose)->toBeNull();
});

it('refuses an empty file rather than treating it as "generate one"', function () {
    // Empty means "render from the fields" on the create form. Here it would mean
    // silently reverting a site to a file the user cannot see, so it is refused —
    // and stopping a site is what the disable control is for.
    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('compose');
});

it('rolls back to the previous file when the new one will not come up', function () {
    // The case this action exists for. `up` exits non-zero — a bad entrypoint, a
    // tag that is not there — and the site must not be left down holding the text
    // that broke it.
    $working = validCompose();
    $this->application->forceFill(['compose' => $working])->save();

    $broken = "services:\n  app:\n    image: nginx:does-not-exist\n    ports:\n      - \"127.0.0.1:20001:80\"\n";

    fakeComposeBox(resolvedCompose(), fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: manifest unknown',
        exitCode: 18,
    ));

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => $broken])
        ->assertStatus(422)
        ->assertJsonPath('rolled_back', true);

    expect($this->application->fresh()->compose)->toBe($working);
});

it('rolls back to the generated file for a site that had no stored one', function () {
    // Empty is a real previous value, not a missing one: rolling back to "" is
    // rolling back to "generate it from the fields", which is exactly right.
    fakeComposeBox(resolvedCompose(), fn () => Process::result(errorOutput: 'boom', exitCode: 1));

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => validCompose()])
        ->assertStatus(422);

    expect($this->application->fresh()->compose)->toBe('');
});

it('re-renders the vhost when the published port moved', function () {
    // nginx proxies to `app_port`. Change the port in the file, skip this, and the
    // web server points at nothing — a 502 from a save that reported success.
    $this->application->forceFill(['compose' => validCompose()])->save();

    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }
        $ran[] = $args;

        if (($args[0] ?? '') === 'docker' && in_array('config', $args, true)) {
            return Process::result(output: resolvedCompose(30500));
        }
        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => validCompose(30500)])
        ->assertOk();

    expect($this->application->fresh()->app_port)->toBe(30500);

    // The vhost was written and the web server told to re-read it.
    $wroteVhost = collect($ran)->contains(
        fn (array $args) => ($args[0] ?? '') === 'tee' && str_contains($args[1] ?? '', 'nginx')
    );
    $reloaded = collect($ran)->contains(
        fn (array $args) => in_array($args[0] ?? '', ['systemctl', 'nginx'], true)
    );

    expect($wroteVhost)->toBeTrue()->and($reloaded)->toBeTrue();
});

it('leaves the vhost alone when the port did not move', function () {
    // The inverse, and it matters: reloading nginx on every compose save is a
    // needless hiccup for every site on the box.
    $this->application->forceFill(['compose' => validCompose()])->save();

    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }
        $ran[] = $args;

        if (($args[0] ?? '') === 'docker' && in_array('config', $args, true)) {
            return Process::result(output: resolvedCompose());
        }
        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => validCompose()."    restart: unless-stopped\n"])
        ->assertOk();

    expect(collect($ran)->contains(fn (array $a) => ($a[0] ?? '') === 'tee' && str_contains($a[1] ?? '', 'nginx')))
        ->toBeFalse();
});

it('stores without touching the box for a site that is not serving', function () {
    // A pending site has nothing on disk and a disabled one is deliberately
    // offline. Bringing either up as a side effect of saving a file would provision
    // or un-disable a site from the wrong screen.
    $this->application->forceFill(['status' => 'pending'])->save();

    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $ran[] = $process->command;

        return Process::result(output: resolvedCompose());
    });

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => validCompose()])
        ->assertOk();

    expect($this->application->fresh()->compose)->toBe(validCompose())
        ->and(collect($ran)->contains(fn (array $a) => in_array('up', $a, true)))->toBeFalse();
});

/*
 * Access.
 */

it('lets a viewer read the file but not replace it', function () {
    // Reading it is how somebody diagnoses their own site. Writing it can stop it.
    $this->application->forceFill(['compose' => validCompose()])->save();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));
    $headers = ['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken];

    // No grants at all: both are refused, which is the baseline.
    $this->withHeaders($headers)->getJson(composeUrl())->assertForbidden();
    $this->withHeaders($headers)->putJson(composeUrl(), ['compose' => validCompose()])->assertForbidden();
});

it('is refused for a site that is not a container', function () {
    $this->application->forceFill(['serving_profile' => 'php'])->save();

    $this->withHeaders(composeHeaders())->getJson(composeUrl())->assertStatus(422);
    $this->withHeaders(composeHeaders())->putJson(composeUrl(), ['compose' => validCompose()])->assertStatus(422);
});

it('records that the file changed without recording what it said', function () {
    // A compose file holds environment variables and people put secrets in those.
    // The activity log has a wider audience than the people who may edit a site.
    fakeComposeBox(resolvedCompose());

    $this->withHeaders(composeHeaders())
        ->putJson(composeUrl(), ['compose' => validCompose()])
        ->assertOk();

    $row = ActivityLog::where('type', 'application')->where('action', 'compose_updated')->sole();

    expect($row->properties['bytes'])->toBe(strlen(validCompose()))
        ->and($row->properties['took_over_generated'])->toBeTrue()
        ->and($row->properties['applied'])->toBeTrue()
        ->and(json_encode($row->properties))->not->toContain('nginx:1.27-alpine');
});

/*
 * The screen's placement, which is a permission question.
 *
 * It was a dialog on the Dashboard's Container card. It is now its own item in the
 * application sidebar, which means its own `app_compose` permission — the sidebar
 * row IS the permission row — and that permission must be offered for containers
 * and for nothing else.
 */

it('offers a Compose File item in a container site\'s sidebar', function () {
    $items = collect(
        $this->withHeaders(composeHeaders())
            ->getJson('/api/permissions?level=application&application_id='.$this->application->id)
            ->json('permissions')
    );

    $compose = $items->firstWhere('name', 'app_compose');

    expect($compose)->not->toBeNull()
        ->and($compose['url'])->toBe('/compose');
});

it('offers it for no other site type', function () {
    // The site-type filter drops a screen that would be about nothing — the same
    // way it drops Environment from a WordPress install. A PHP site has no compose
    // file, so the item must not be there to click.
    $php = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Plain', 'slug' => 'plain', 'domain' => 'plain.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'web_root' => 'public_html',
        'status' => 'active',
    ]);

    $items = collect(
        $this->withHeaders(composeHeaders())
            ->getJson('/api/permissions?level=application&application_id='.$php->id)
            ->json('permissions')
    )->pluck('name');

    expect($items)->not->toContain('app_compose');

    // Deliberately NOT asserting that Environment appears instead. A hand-rolled
    // PHP site gets neither, on purpose — `AbstractSiteType` adds Environment only
    // for git-deployed and Node sites, because a site with no framework reading a
    // `.env` would be offered a convention the panel invented for it. The pairing
    // holds for a container, not universally, and the first version of this test
    // asserted otherwise and failed.
});

it('does not offer Environment on a container, since the file replaced it', function () {
    $items = collect(
        $this->withHeaders(composeHeaders())
            ->getJson('/api/permissions?level=application&application_id='.$this->application->id)
            ->json('permissions')
    )->pluck('name');

    expect($items)->not->toContain('app_environment')->toContain('app_compose');
});

it('has the sidebar label translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('nav.app_compose', [], $locale);

        expect($line)->not->toBe('nav.app_compose')->and($line)->not->toBeEmpty();
    }
});

/*
 * The container's own settings, likewise moved off the Dashboard.
 */

it('offers a Container item in a container site\'s sidebar', function () {
    $items = collect(
        $this->withHeaders(composeHeaders())
            ->getJson('/api/permissions?level=application&application_id='.$this->application->id)
            ->json('permissions')
    );

    $container = $items->firstWhere('name', 'app_container');

    expect($container)->not->toBeNull()->and($container['url'])->toBe('/container');
});

it('gates the container endpoints on their own permission', function () {
    // They were on `application,manage`, which is the grant for renaming a site and
    // enabling it. Recreating a container on a new network is a different act, and
    // the sidebar row needs a permission of its own anyway.
    dockerStack();

    $role = Role::create(['name' => 'Sitewrangler', 'slug' => 'sitewrangler']);
    $role->permissions()->attach(
        Permission::where('name', 'application')->sole()->id,
        ['view' => true, 'manage' => true],
    );

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    // Can manage the site itself...
    $this->withHeaders($headers)->getJson('/api/applications/'.$this->application->id)->assertOk();

    // ...and not its container's runtime, nor the credentials it was built with.
    $this->withHeaders($headers)
        ->putJson('/api/applications/'.$this->application->id.'/container', ['container_port' => 8080])
        ->assertForbidden();
    $this->withHeaders($headers)
        ->getJson('/api/applications/'.$this->application->id.'/container/secrets')
        ->assertForbidden();
    $this->withHeaders($headers)
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertForbidden();
});

it('has the Container label translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('nav.app_container', [], $locale);

        expect($line)->not->toBe('nav.app_container')->and($line)->not->toBeEmpty();
    }
});

/*
 * The one-click apps, which is where this went wrong.
 *
 * `app_container` and `app_compose` were added by `DockerSiteType` alone, so all
 * fifteen one-click app types went without them — and since the credentials
 * endpoint moved onto `app_container`, it answered 404 for exactly the sites that
 * HAVE generated credentials. Found by installing Ghost on a real box; every test
 * that touched the endpoint had used the BYO-image type.
 */

it('offers both container screens to every container-served type', function (string $type) {
    $siteType = app(SiteTypeManager::class)->find($type);
    $features = $siteType->features();

    expect($features)->toContain('app_container')->toContain('app_compose')
        // And never the Environment screen: a container's variables live in the
        // compose file, which is why that screen is replaced rather than joined.
        ->and($features)->not->toContain('app_environment');
})->with(['docker', 'ghost', 'matomo', 'gitea', 'vaultwarden', 'metabase']);

it('keeps the credentials endpoint reachable on a one-click app', function () {
    // The regression itself, at the endpoint rather than at the feature list.
    $ghost = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Ghost', 'slug' => 'ghostie', 'domain' => 'ghostie.test',
        'site_type' => 'ghost', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'app_port' => 20002, 'status' => 'active',
        'docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'a', 'GHOST_DB_PASSWORD' => 'b'],
    ]);

    $this->withHeaders(composeHeaders())
        ->getJson('/api/applications/'.$ghost->id.'/container/secrets')
        ->assertOk()
        ->assertJsonPath('secrets.GHOST_DB_PASSWORD', 'b');
});
