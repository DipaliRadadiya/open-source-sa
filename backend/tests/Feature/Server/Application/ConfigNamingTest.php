<?php

use App\Actions\Server\Application\UpdateApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The web-server config is named after the application, not its domain.
 *
 * A domain is neither unique nor stable. Two applications could claim the same
 * one and silently overwrite each other's vhost — no error, the second site
 * simply replaced the first — and changing a domain left the old file in
 * `sites-enabled` under a name nothing could address any more, still serving.
 *
 * The name is what the user calls the site and is now unique; the slug is that
 * name made into a filename, because a name is free text and a path is not.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

function namingHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function fakeNamingServer(): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        $ran->append($args);

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function namingApp(array $overrides = []): Application
{
    $app = Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'My Blog',
        'domain' => 'blog.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ], $overrides));

    $app->forceFill(['slug' => Application::uniqueSlug((string) $app->name, $app->id)])->save();

    return $app->fresh();
}

function configPathFor(Application $application): string
{
    return app(WebServerManager::class)->driver()->configPath($application);
}

it('names the config after the application, not the domain', function () {
    $app = namingApp();

    expect(configPathFor($app))->toEndWith('/my-blog.conf')
        ->and(configPathFor($app))->not->toContain('blog.test');
});

it('gives two applications on the same domain different config files', function () {
    $one = namingApp(['name' => 'Marketing site']);
    $two = namingApp(['name' => 'Docs site']);

    // Nothing stops two applications sharing a domain, and before this they
    // shared a file: the second silently replaced the first.
    expect($one->domain)->toBe($two->domain)
        ->and(configPathFor($one))->not->toBe(configPathFor($two));
});

it('refuses a duplicate name', function () {
    namingApp(['name' => 'Taken']);

    $this->withHeaders(namingHeaders())
        ->postJson('/api/applications', [
            'system_user_id' => $this->su->id,
            'name' => 'Taken',
            'domain' => 'other.test',
            'site_type' => 'php',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('keeps two names that slug to the same string apart', function () {
    $one = namingApp(['name' => 'My Blog']);
    $two = namingApp(['name' => 'my blog']);

    // "My Blog" and "my blog" are different names and both allowed, but they
    // slug identically — which would have put two sites in one file.
    expect($one->slug)->toBe('my-blog')
        ->and($two->slug)->toBe('my-blog-2')
        ->and(configPathFor($one))->not->toBe(configPathFor($two));
});

it('does not move the config, because a site cannot be renamed', function () {
    $app = namingApp();
    $before = configPathFor($app);

    $ran = fakeNamingServer();

    // The endpoint refuses `name` outright; this goes straight to the action
    // to prove the action no longer acts on one either, since that is what
    // used to move the file.
    app(UpdateApplication::class)->execute($app, ['name' => 'Renamed blog']);

    // The slug is the key to six filenames — the vhost, the pool and its
    // socket, the directory, the logs, the jail, the units — and only the
    // vhost ever moved. Now nothing does.
    expect($app->fresh()->slug)->toBe($app->slug)
        ->and(configPathFor($app->fresh()))->toBe($before)
        ->and(count((array) $ran))->toBe(0);
});

it('refuses a rename at the endpoint, rather than quietly ignoring it', function () {
    $app = namingApp();

    // Silence would be worse than a refusal: API_REFERENCE used to advertise
    // this, so a caller following it would get a 200 and no rename.
    test()->actingAs(test()->admin)
        ->putJson("/api/applications/{$app->id}", ['name' => 'Renamed blog'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect($app->fresh()->name)->toBe($app->name);
});

it('removes the old domain-named config when resyncing an existing install', function () {
    $app = namingApp();

    $ran = fakeNamingServer();

    app(SiteConfigResyncer::class)->run();

    // A site provisioned before this change still has `{domain}.conf` on disk.
    // Both files loaded means two server blocks claiming the same names.
    $removedLegacy = false;

    foreach ($ran as $args) {
        if (($args[0] ?? '') === 'rm' && in_array('/etc/nginx/sites-enabled/blog.test.conf', $args, true)) {
            $removedLegacy = true;
        }
    }

    expect($removedLegacy)->toBeTrue('the legacy domain-named config was not removed');
});

it('still addresses the file a pre-slug row actually has on disk', function () {
    $app = namingApp();
    $app->forceFill(['slug' => null])->save();

    // A row written before the slug column exists. Resolving it to a slug-based
    // name would point the panel at a file that was never written.
    expect(configPathFor($app->fresh()))->toEndWith('/blog.test.conf');
});
