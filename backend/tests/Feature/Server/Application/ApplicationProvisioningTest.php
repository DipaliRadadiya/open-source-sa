<?php

use App\Exceptions\Server\Application\UnsupportedWebServerException;
use App\Jobs\ProvisionApplication;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\Applications\StrandedProvisioning;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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

    config([
        'server.web_server_drivers.nginx.sites_dir' => '/etc/nginx/sites-enabled',
        // The real file lives here; sites-enabled holds a symlink to it.
        // Unset, every assertion about the written config compared against a
        // path beginning with a bare slash and quietly matched nothing.
        'server.web_server_drivers.nginx.sites_available_dir' => '/etc/nginx/sites-available',
    ]);
});

function makeApp(array $overrides = []): Application
{
    // `forceCreate`, because `slug` is not fillable — the create action
    // assigns it. Through `create()` the slug was silently dropped and every
    // path in this file came out as `{home}/public_html`, which is the
    // pre-slug layout no server has used since August.
    return Application::forceCreate(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        // Named by slug, never domain. Every real application has one — the
        // create action assigns it — and `rootPath()` only falls back to the
        // home directory for rows written before the column existed. Without
        // it here the fixture described a layout the panel stopped using in
        // August, and these assertions pinned the old one.
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
    ], $overrides));
}

function provisionHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('creates the directory, writes a tested config and reloads', function () {
    Process::fake();
    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();
    expect($app->status->value)->toBe('active');
    expect($app->steps)->toBe([
        'ensure_account', 'create_directory', 'set_ownership', 'placeholder', 'harden_php', 'create_php_pool', 'write_config', 'test_config', 'reload',
    ]);

    // `{home}/{slug}/public_html` — the document root, not the site directory:
    // `.panel`, the logs and the `.env` live beside it and must not be served.
    Process::assertRan(fn ($p) => $p->command === ['mkdir', '-p', '/home/deploy/shop/public_html']);
    Process::assertRan(fn ($p) => $p->command === ['chown', '-R', 'deploy:deploy', '/home/deploy/shop/public_html']);
    // Named by slug, and written to sites-available — sites-enabled gets the
    // symlink. The domain-named file is what a row predating the slug column
    // falls back to, not what a current site produces.
    Process::assertRan(fn ($p) => $p->command === ['tee', '/etc/nginx/sites-available/shop.conf']
        && str_contains($p->input, 'server_name shop.example.com')
        && str_contains($p->input, 'root /home/deploy/shop/public_html;')
        // Its OWN pool socket, named for the slug — not the server-wide
        // php8.4-fpm.sock. That is the whole point of per-site isolation: a
        // vhost still pointing at the shared socket is a site running as
        // www-data with none of its own settings.
        && str_contains($p->input, '/run/php/shop.sock'));
    Process::assertRan(fn ($p) => $p->command === ['nginx', '-t']);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'nginx']);
});

it('removes the config and does not reload when the config test fails', function () {
    // A broken vhost that reaches a reload takes every other site down.
    Process::fake(fn ($process) => $process->command === ['nginx', '-t']
        ? Process::result(errorOutput: 'invalid directive', exitCode: 1)
        : Process::result(exitCode: 0));

    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();
    expect($app->status->value)->toBe('failed');
    expect($app->failed_step)->toBe('test_config');
    expect($app->reference)->not->toBeEmpty();

    // Our config is taken back out, and nothing is reloaded.
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.conf']);
    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'reload', 'nginx']);
});

/**
 * sites-available and sites-enabled as the fake commands leave them, and an
 * `nginx -t` that fails while any enabled file holds $broken — what one bad
 * site does to the box.
 */
function provisionDisk(string $broken, array $failCommands = []): ArrayObject
{
    $disk = new ArrayObject(['files' => [], 'links' => [], 'tests' => 0]);

    Process::fake(function ($process) use ($disk, $broken, $failCommands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $files = $disk['files'];
        $links = $disk['links'];

        foreach ($failCommands as $prefix) {
            if (array_slice($args, 0, count($prefix)) === $prefix) {
                return Process::result(errorOutput: 'refused', exitCode: 1);
            }
        }

        switch ($args[0] ?? '') {
            case 'tee':
                $files[$args[1]] = (string) $process->input;
                break;
            case 'ln':
                $links[end($args)] = $args[count($args) - 2];
                break;
            case 'rm':
                unset($files[end($args)], $links[end($args)]);
                break;
            case 'nginx':
                if (($args[1] ?? '') === '-t') {
                    $disk['tests']++;
                    foreach ($links as $target) {
                        if (str_contains($files[$target] ?? '', $broken)) {
                            return Process::result(errorOutput: 'nginx: [emerg] unknown directive', exitCode: 1);
                        }
                    }
                }
                break;
        }

        $disk['files'] = $files;
        $disk['links'] = $links;

        return Process::result();
    });

    return $disk;
}

function runProvision(Application $app): Application
{
    (new ProvisionApplication($app->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));

    return $app->fresh();
}

it('takes a failed site out of sites-enabled, so the next site still passes its config test', function () {
    // Ubuntu 22.04, 28 Oct: one site whose config nginx refused, and every
    // site created after it failed at test_config as well.
    $disk = provisionDisk('server_name broken.example.com');

    $broken = runProvision(makeApp(['name' => 'Broken', 'slug' => 'broken', 'domain' => 'broken.example.com']));

    expect($broken->status->value)->toBe('failed')
        ->and($broken->failed_step)->toBe('test_config')
        ->and($broken->reference)->not->toBeEmpty()
        ->and($disk['links'])->not->toHaveKey('/etc/nginx/sites-enabled/broken.conf')
        ->and($disk['files'])->not->toHaveKey('/etc/nginx/sites-available/broken.conf');

    $next = runProvision(makeApp(['name' => 'Next', 'slug' => 'next', 'domain' => 'next.example.com']));

    expect($next->status->value)->toBe('active')
        ->and($disk['links'])->toHaveKey('/etc/nginx/sites-enabled/next.conf');
});

it('tests the config again after taking a failed site out', function () {
    $disk = provisionDisk('server_name shop.example.com');

    runProvision(makeApp());

    // Once for the site, once to prove the server is healthy without it.
    expect($disk['tests'])->toBe(2);
});

it('logs it when the config still fails after the failed site came out', function () {
    // Broken before this site: nothing it did, and nothing it can undo.
    Process::fake(fn ($process) => $process->command === ['nginx', '-t']
        ? Process::result(errorOutput: 'invalid directive', exitCode: 1)
        : Process::result());
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('web server config still failing after removing a failed site', Mockery::on(
        fn (array $context) => $context['test_reference'] !== '' && array_key_exists('remove_reference', $context),
    ));
    Log::shouldReceive('info', 'debug', 'error', 'notice')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();

    $app = runProvision(makeApp());

    expect($app->failed_step)->toBe('test_config');
});

it('takes the config back out when writing it stops halfway', function () {
    // The file is written, then enabling it is refused: what landed was left
    // behind for the next write, resync or person to trip over.
    $disk = provisionDisk('nothing-is-broken', [['ln']]);

    $app = runProvision(makeApp());

    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('write_config')
        ->and($disk['files'])->not->toHaveKey('/etc/nginx/sites-available/shop.conf');
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.conf']);
    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'reload', 'nginx']);
});

it('stops at the first failed step and records where it broke', function () {
    Process::fake(fn ($process) => $process->command[0] === 'mkdir'
        ? Process::result(errorOutput: 'permission denied', exitCode: 1)
        : Process::result(exitCode: 0));

    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();
    expect($app->status->value)->toBe('failed');
    expect($app->failed_step)->toBe('create_directory');
    // Nothing further was attempted.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('never leaks the raw server error into the response', function () {
    Process::fake(fn ($process) => $process->command === ['nginx', '-t']
        ? Process::result(errorOutput: 'nginx: [emerg] secret internal path /etc/foo', exitCode: 1)
        : Process::result(exitCode: 0));

    $app = makeApp();
    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $body = $this->withHeaders(provisionHeaders())->getJson("/api/applications/{$app->id}")->json();

    expect(json_encode($body))->not->toContain('secret internal path');
    expect($body['application']['reference'])->not->toBeEmpty();
});

it('is idempotent when the job runs twice', function () {
    Process::fake();
    $app = makeApp();

    foreach (range(1, 2) as $ignored) {
        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );
    }

    $app->refresh();
    expect($app->status->value)->toBe('active');
    // mkdir -p and an overwriting tee converge rather than compounding.
    //
    // The list rather than a count. `harden_php` was added to the provisioner
    // without this number following it, and a bare `toHaveCount(8)` reports
    // that as "9 does not match 8" — which says a step was duplicated, the one
    // thing this test exists to catch, when in fact a step was added. Naming
    // them means the next addition fails with a diff that says which.
    expect($app->steps)->toBe([
        'ensure_account',
        'create_directory',
        'set_ownership',
        'placeholder',
        'harden_php',
        'create_php_pool',
        'write_config',
        'test_config',
        'reload',
    ]);
});

it('writes a static config without a php handler', function () {
    Process::fake();
    $app = makeApp(['site_type' => 'static', 'serving_profile' => 'static', 'php_version' => null]);

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        // Content is written to sites-available; sites-enabled only gets a
        // symlink to it (see AbstractWebServerDriver::apply()).
        && str_contains((string) $p->command[1], 'sites-available')
        && ! str_contains($p->input, 'fastcgi_pass'));
});

it('refuses to provision when the web server is not one we can configure', function () {
    Process::fake();
    // Was `openlitespeed`, which the panel can now configure. The rule this
    // guards is unchanged — a web server with no driver is refused rather
    // than guessed at — so it needs a web server that genuinely has none.
    ServerCapability::query()->update(['web_server' => 'caddy']);

    $app = makeApp();

    expect(fn () => app(ApplicationProvisioner::class)->provision($app->load('systemUser')))
        ->toThrow(UnsupportedWebServerException::class);

    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('queues provisioning when an application is created', function () {
    Queue::fake();

    $this->withHeaders(provisionHeaders())->postJson('/api/applications', [
        'site_type' => 'php',
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'system_user_id' => $this->su->id,
    ])->assertCreated();

    Queue::assertPushed(ProvisionApplication::class);
});

it('can retry a failed provision explicitly', function () {
    Queue::fake();
    $app = makeApp(['status' => 'failed', 'failed_step' => 'reload', 'failed_reason' => 'not_answering', 'reference' => 'abc']);

    $this->withHeaders(provisionHeaders())
        ->postJson("/api/applications/{$app->id}/provision")
        ->assertStatus(202)
        ->assertJsonPath('application.status', 'provisioning');

    Queue::assertPushed(ProvisionApplication::class);
    // The previous failure is cleared so the UI doesn't show a stale error.
    expect($app->fresh()->failed_step)->toBeNull()
        // Measured on a real server: a retried n8n came up Active still
        // saying "not answering".
        ->and($app->fresh()->failed_reason)->toBeNull();
});

it('does not queue a second provision while one is already running', function () {
    Queue::fake();
    $app = makeApp(['status' => 'provisioning', 'steps' => ['create_directory']]);

    $this->withHeaders(provisionHeaders())
        ->postJson("/api/applications/{$app->id}/provision")
        ->assertStatus(202)
        ->assertJsonPath('application.status', 'provisioning');

    Queue::assertNothingPushed();
    expect($app->fresh()->steps)->toBe(['create_directory']);
});

it('uses one unique provisioning job per application', function () {
    expect((new ProvisionApplication(42))->uniqueId())->toBe('application-provision-42');
});

it('keeps a support reference when the provisioning worker dies', function () {
    $app = makeApp(['status' => 'provisioning']);

    (new ProvisionApplication($app->id))->failed(new RuntimeException('worker lost'));

    $app->refresh();
    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('worker')
        ->and($app->reference)->not->toBeEmpty();
});

it('refuses to delete an application while provisioning is running', function () {
    Process::fake();
    $app = makeApp(['status' => 'provisioning']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertStatus(503);

    expect(Application::find($app->id))->not->toBeNull();
    Process::assertNothingRan();
});

it('removes the site config on delete but keeps the files by default', function () {
    Process::fake();
    $app = makeApp(['status' => 'active']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/nginx/sites-enabled/shop.conf']);
    // Someone's code must never disappear as a side effect of removing a record.
    Process::assertNotRan(fn ($p) => $p->command[0] === 'rm' && ($p->command[1] ?? '') === '-rf');
    expect(Application::count())->toBe(0);
});

it('removes the files only when explicitly asked', function () {
    Process::fake();
    $app = makeApp(['status' => 'active']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}?remove_files=true")
        ->assertOk();

    // The whole site directory, not just what was served.
    Process::assertRan(fn ($p) => $p->command === ['rm', '-rf', '/home/deploy/shop']);
});

it('touches nothing on the server when deleting an app that was never provisioned', function () {
    Process::fake();
    $app = makeApp(['status' => 'pending']);

    $this->withHeaders(provisionHeaders())
        ->deleteJson("/api/applications/{$app->id}")
        ->assertOk();

    Process::assertNothingRan();
});

it('denies provisioning with view-only access', function () {
    Queue::fake();
    $user = User::factory()->create();
    grantPermission($user, 'application', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;
    $app = makeApp(['status' => 'failed']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/applications/{$app->id}/provision")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

/*
 * A new PHP site starts with dangerous functions disabled.
 *
 * The default was `null`, so every freshly created site could call exec,
 * shell_exec, system, passthru, proc_open and popen — what a web shell needs
 * and what almost no application does. Both lists and the UI presets for them
 * already existed; nothing applied either unless someone went looking.
 */

function provisionApp(array $overrides = []): Application
{
    Process::fake();
    $app = makeApp($overrides);

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    return $app->fresh(['phpSettings']);
}

it('disables the strict function list on a new PHP site', function () {
    $app = provisionApp();

    expect($app->phpSettings)->not->toBeNull()
        ->and($app->phpSettings->disable_functions)
        ->toBe(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS);
});

it('turns open_basedir on for a new PHP site, as v7 does', function () {
    // The column defaults to off, so the model's default alone never reached
    // a real site — caught on a live server, not by a unit test.
    expect(provisionApp()->phpSettings->open_basedir_enabled)->toBeTrue();
});

it('keeps the open_basedir a site already had (a clone, a re-provision)', function () {
    $app = makeApp();
    ApplicationPhpSettings::forceCreate(['application_id' => $app->id, 'open_basedir_enabled' => false]);

    Process::fake();
    (new ProvisionApplication($app->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));

    expect($app->fresh(['phpSettings'])->phpSettings->open_basedir_enabled)->toBeFalse();
});

it('disables it before the config that carries it is written', function () {
    // FPM renders these into the pool and OpenLiteSpeed into the site's ini.
    // Written after either, a new site would ship its first config without the
    // list and pick it up only on some later, unrelated change.
    $steps = provisionApp()->steps;

    expect(array_search('harden_php', $steps, true))
        ->toBeLessThan(array_search('create_php_pool', $steps, true))
        ->and(array_search('harden_php', $steps, true))
        ->toBeLessThan(array_search('write_config', $steps, true));
});

it('leaves a site that already exists exactly as it was', function () {
    // THE REASON THIS IS NOT IN defaults(). That method fills every value a
    // site has not set, so putting the list there would reach existing sites
    // on whatever unrelated event next re-rendered their configuration — a
    // resync, a domain change, a certificate — and a working site would start
    // refusing calls it made yesterday with nothing connecting the two.
    $existing = makeApp(['slug' => 'old', 'domain' => 'old.example.com']);

    expect(ApplicationPhpSettings::query()->where('application_id', $existing->id)->exists())
        ->toBeFalse()
        ->and(ApplicationPhpSettings::defaults()['disable_functions'])->toBeNull();
});

it('does not write PHP settings for a site that does not run PHP', function () {
    // A static or proxy site has no interpreter to restrict, and a row here
    // would show PHP settings on a screen for a site that has none.
    $app = provisionApp(['serving_profile' => 'static', 'site_type' => 'static']);

    expect($app->steps)->not->toContain('harden_php')
        ->and($app->phpSettings)->toBeNull();
});

/*
 * The `.env` a container's compose file insists on.
 *
 * `compose.blade.php` declares `env_file` unconditionally, and Compose treats a
 * missing one as fatal — so a container site with no `.env` cannot start. The
 * guard here read `site_type !== 'git'`, which meant no site built from the
 * image/port fields ever got one. It failed at `container_start`, several steps
 * after the step that did not create the file.
 */

it('creates the env file a container site cannot start without', function () {
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $ran[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $app = makeApp([
        'site_type' => 'docker',
        'serving_profile' => 'docker',
        'image' => 'nginx:1.27-alpine',
        'container_port' => 80,
        'web_root' => 'public_html',
    ]);

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $env = $app->fresh()->envPath();

    // The same path the compose file names. Asserting on the rendered file's
    // string instead would pass while nothing created it — which is exactly how
    // this shipped.
    expect(collect($ran)->contains(
        fn (array $command): bool => in_array('touch', $command, true) && in_array($env, $command, true),
    ))->toBeTrue("nothing touched {$env}");
});

it('points nginx at the port a pasted compose file publishes, before calling the site active', function () {
    // write_config renders the vhost with the allocated port; starting the
    // container then moves app_port to the one the file publishes. Measured
    // on 2026-10-07: nginx on 3014, the container on 3992, the site `active`
    // and `running` and a 502 for good, because only the certificate step
    // rewrote the vhost and it declines a domain not pointed here yet.
    $ran = [];
    $vhosts = [];
    Process::fake(function ($process) use (&$ran, &$vhosts) {
        $command = $process->command;
        $ran[] = $command;
        $line = implode(' ', (array) $command);

        if (str_contains($line, 'tee /etc/nginx/sites-available/shop.conf')) {
            $vhosts[] = (string) $process->input;
        }

        return match (true) {
            str_contains($line, 'config --format json') => Process::result(json_encode(['services' => ['web' => [
                'image' => 'traefik/whoami:v1.11',
                'ports' => [['host_ip' => '127.0.0.1', 'published' => '3992', 'target' => 80]],
            ]]])),
            str_contains($line, '--status running') => Process::result("abc\n"),
            str_contains($line, 'curl') => Process::result('200'),
            default => Process::result(),
        };
    });

    $app = makeApp([
        'site_type' => 'docker',
        'serving_profile' => 'docker',
        'app_port' => 3014,
        'compose' => "services:\n  web:\n    image: traefik/whoami:v1.11\n    ports:\n      - \"127.0.0.1:3992:80\"\n",
        'web_root' => 'public_html',
    ]);

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();

    expect($app->status->value)->toBe('active')
        ->and($app->app_port)->toBe(3992)
        ->and($vhosts)->not->toBeEmpty()
        ->and(end($vhosts))->toContain('127.0.0.1:3992')
        ->and(end($vhosts))->not->toContain('127.0.0.1:3014');

    // Reloaded after that rewrite, not only after the first one.
    $lines = array_map(fn ($c): string => implode(' ', (array) $c), $ran);
    $lastVhost = max(array_keys(array_filter($lines, fn (string $l): bool => str_contains($l, 'tee /etc/nginx/sites-available/shop.conf'))));
    $reloads = array_keys(array_filter($lines, fn (string $l): bool => str_contains($l, 'reload nginx')));

    expect(max($reloads))->toBeGreaterThan($lastVhost);
});

it('still does not create one for a PHP site, which has no use for it', function () {
    // The guard exists. Widening it to every site type would put a dotfile in
    // the root of every static and WordPress site for no reader.
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $ran[] = $process->command;

        return Process::result(exitCode: 0);
    });

    $app = makeApp();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    expect(collect($ran)->contains(
        fn (array $command): bool => in_array('touch', $command, true)
            && in_array($app->fresh()->envPath(), $command, true),
    ))->toBeFalse();
});

/*
 * An install job that died without a word — worker killed, disk full — left the
 * site `provisioning` for good, and Delete refuses a provisioning site, so the
 * user could never remove it (found on a test server whose disk filled,
 * 2026-10-18). Past its own budget nothing can still be installing it.
 */
describe('a site whose install job died without finishing', function () {
    function provisionDeadline(): int
    {
        return app(ProvisioningBudget::class)->forSiteType('php') + ProvisionApplication::UNIQUE_LOCK_GRACE;
    }

    it('is marked failed once its time budget has passed', function () {
        $app = makeApp(['status' => 'provisioning', 'provisioning_started_at' => now()->subSeconds(provisionDeadline() + 60)]);

        $this->artisan('applications:close-stranded')->assertSuccessful();

        $app->refresh();
        expect($app->status->value)->toBe('failed')
            ->and($app->failed_step)->toBe('worker')
            ->and($app->reference)->not->toBeEmpty()
            ->and(ActivityLog::where('action', 'provision_failed')->exists())->toBeTrue();
    });

    it('is left alone while its job may still be running', function () {
        $app = makeApp(['status' => 'provisioning', 'provisioning_started_at' => now()->subSeconds(provisionDeadline() - 60)]);

        $this->artisan('applications:close-stranded')->assertSuccessful();

        expect($app->fresh()->status->value)->toBe('provisioning');
    });

    it('is left alone while it is still waiting in the queue, however long', function () {
        $app = makeApp(['status' => 'provisioning', 'provisioning_started_at' => null, 'created_at' => now()->subDays(3)]);

        $this->artisan('applications:close-stranded')->assertSuccessful();

        expect($app->fresh()->status->value)->toBe('provisioning');
    });

    it('can be deleted', function () {
        Process::fake();
        $app = makeApp(['status' => 'provisioning', 'provisioning_started_at' => now()->subSeconds(provisionDeadline() + 60)]);

        $this->withHeaders(provisionHeaders())
            ->deleteJson("/api/applications/{$app->id}")
            ->assertOk();

        expect(Application::find($app->id))->toBeNull();
    });

    it('still refuses to delete a site whose install is running', function () {
        Process::fake();
        $app = makeApp(['status' => 'provisioning', 'provisioning_started_at' => now()->subMinute()]);

        $this->withHeaders(provisionHeaders())
            ->deleteJson("/api/applications/{$app->id}")
            ->assertStatus(503);

        expect(Application::find($app->id))->not->toBeNull()
            ->and($app->fresh()->status->value)->toBe('provisioning');
        Process::assertNothingRan();
    });

    it('does not mark failed a retry that started a moment ago', function () {
        $app = makeApp(['status' => 'provisioning', 'provisioning_started_at' => now()->subSeconds(provisionDeadline() + 60)]);
        $seen = $app->fresh();
        // A retry restamps the start between the check and the write.
        $app->update(['provisioning_started_at' => now()]);

        expect(app(StrandedProvisioning::class)->close($seen))->toBeFalse()
            ->and($app->fresh()->status->value)->toBe('provisioning');
    });

    it('leaves finished and failed sites alone', function (string $status) {
        $app = makeApp(['status' => $status, 'provisioning_started_at' => now()->subDays(2)]);

        $this->artisan('applications:close-stranded')->assertSuccessful();

        expect($app->fresh()->status->value)->toBe($status)
            ->and(app(StrandedProvisioning::class)->isStranded($app->fresh()))->toBeFalse();
    })->with(['active', 'failed']);

    it('is scheduled', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('applications:close-stranded')->assertSuccessful();
    });
});
