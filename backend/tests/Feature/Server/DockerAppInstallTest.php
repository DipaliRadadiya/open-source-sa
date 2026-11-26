<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\SiteTypeText;
use App\Services\Applications\Types\AbstractDockerAppType;
use App\Services\Applications\Types\RecipeSiteType;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use App\Services\Server\HostCpus;
use App\Services\Server\ServerOps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/*
 * One-click Docker applications.
 *
 * These tests are about the compose file the panel writes on the user's behalf.
 * Three properties it must have, none visible from "the site came up":
 *
 *  - Secrets are GENERATED per site. A value in a template would be shared by
 *    every panel-installed copy of that app.
 *  - The hardening of the generic template (loopback-only publishing, a memory
 *    ceiling, bounded logs) is present in every service of every app.
 *  - The database is not published to the host.
 */
beforeEach(function () {
    // Permissive default so a test about the rendered compose file need not describe the box.
    // `cat` fails because it is the existence probe for starter files, which should then be
    // written; everything else succeeds. Tests re-fake when the box is what they are about.
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return ($args[0] ?? '') === 'cat'
            ? Process::result(exitCode: 1, errorOutput: 'No such file')
            : Process::result(exitCode: 0);
    });

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
});

function dockerAppSite(string $type, int $port = 20101, string $user = 'owner'): Application
{
    $systemUser = SystemUser::create(['username' => $user, 'home_path' => "/home/{$user}"]);

    return Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => ucfirst($type).' '.$port,
        'slug' => $type.'-'.$port,
        // Slugs may contain underscores; real hostnames accepted by the API may not.
        'domain' => str_replace('_', '-', $type)."-{$port}.example.com",
        'web_root' => 'public_html',
        'site_type' => $type,
        'serving_profile' => 'docker',
        'status' => 'pending',
        'app_port' => $port,
    ]);
}

function installDockerApp(Application $application): Application
{
    app(DockerAppInstaller::class)->install($application, '/home/owner/site/public_html', []);

    return $application->fresh();
}

/**
 * Did the stored secret actually reach the rendered file?
 *
 * Verbatim for every app but BookStack, whose `APP_KEY` must be `base64:` plus
 * 32 base64-encoded bytes (the template encodes the 32-character secret).
 * Checked as an alternative rather than a loose substring match, which would
 * also pass for a truncated or re-generated key.
 */
function secretReachedFile(string $compose, string $secret): bool
{
    return str_contains($compose, $secret)
        || str_contains($compose, 'base64:'.base64_encode($secret));
}

dataset('docker apps', [
    'ghost' => ['ghost', 2368, ['content', 'db']],
    'nocodb' => ['nocodb', 8080, ['data', 'db']],
    'metabase' => ['metabase', 3000, ['db']],
    'wikijs' => ['wikijs', 3000, ['db']],
    'mattermost' => ['mattermost', 8065, ['data', 'config', 'plugins', 'client-plugins', 'logs', 'db']],
    'matomo' => ['matomo', 80, ['app', 'db']],
    // Group A: one container each, with the shared compose shape.
    'vaultwarden' => ['vaultwarden', 80, ['data']],
    'gitea' => ['gitea', 3000, ['data']],
    'forgejo' => ['forgejo', 3000, ['data']],
    'freshrss' => ['freshrss', 80, ['data']],
    'stirlingpdf' => ['stirlingpdf', 8080, ['data']],
    'ittools' => ['ittools', 80, []],
    // No named volume: Glance's config is a bind mount in the site directory (a file users edit).
    'glance' => ['glance', 8080, []],
    'homepage' => ['homepage', 3000, ['config']],
    // One container, but with a generated admin secret.
    'grafana' => ['grafana', 3000, ['data']],
    'bookstack' => ['bookstack', 80, ['config', 'db']],
    'wordpress_container' => ['wordpress_container', 80, ['app', 'db']],
    // Four services: a Rails app, a Sidekiq worker, pgvector and Redis.
    'chatwoot' => ['chatwoot', 3000, ['storage', 'db', 'redis']],
    // Nothing to persist: a static bundle.
    'excalidraw' => ['excalidraw', 80, []],
    // Strapi is not listed: it publishes no official image on Docker Hub.
]);

it('generates a password that satisfies a policy, not merely a random one', function () {
    // An alphanumeric password makes Chatwoot's onboarding create no user, yet it still answers
    // the same redirect. Run repeatedly because the guarantee must be structural: hoping 32
    // random alphanumerics contain an upper-case letter passes almost always.
    foreach (range(1, 40) as $run) {
        // A distinct port and system user per run (the username is unique).
        $password = installDockerApp(dockerAppSite('chatwoot', 20100 + $run, "owner{$run}"))
            ->docker_secrets['ADMIN_PASSWORD'];

        expect(strlen($password))->toBeGreaterThanOrEqual(24)
            ->and($password)->toMatch('/[A-Z]/')
            ->and($password)->toMatch('/[a-z]/')
            ->and($password)->toMatch('/[0-9]/')
            ->and($password)->toMatch('/[!@#%^*\-_=+]/');
    }

    // Apps that declare no policy stay alphanumeric: a symbol in an env-read password risks
    // quoting bugs.
    expect(installDockerApp(dockerAppSite('grafana', 20199, 'gfowner'))->docker_secrets['GF_SECURITY_ADMIN_PASSWORD'])
        ->toMatch('/^[A-Za-z0-9]+$/');
});

it('claims the first-run endpoint, and survives being called at all', function () {
    // The endpoint answers 200 while the setup page is open, the panel posts the generated
    // credentials, and the controller closes it, so the re-check sees a redirect. If it still
    // answers 200 the owner was not created and provisioning must fail, not report Active.
    // Also proves `afterStart()` can be called at all.
    $application = installDockerApp(dockerAppSite('chatwoot'));
    $application->forceFill([
        'app_port' => 3002,
        'settings' => ['admin_email' => 'owner@example.com'],
    ])->save();

    $posted = null;
    $postedBody = null;
    $checks = 0;

    Process::fake(function ($process) use (&$posted, &$postedBody, &$checks) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') !== 'curl') {
            return Process::result(exitCode: 0);
        }

        // The POST carries `-X POST`; the probe does not.
        if (in_array('POST', $args, true)) {
            $posted = $args;
            $postedBody = (string) $process->input;

            return Process::result(exitCode: 0);
        }

        // Open first, closed once the post has happened.
        $checks++;

        return Process::result(output: $posted === null ? '200' : '302');
    });

    app(DockerAppInstaller::class)->afterStart($application->fresh(), '/home/owner/site/public_html');

    $argv = implode(' ', $posted);

    expect($posted)->not->toBeNull('the first-run endpoint was never claimed')
        ->and($argv)->toContain('/installation/onboarding')
        ->and($checks)->toBeGreaterThanOrEqual(2);

    // The credentials go on stdin, not in argv: an argument is readable through `ps` and is
    // logged verbatim, since `CommandRedactor` matches `--flags` and URL query parameters only.
    expect($argv)->not->toContain('user[password]')
        ->and($argv)->not->toContain($application->fresh()->docker_secrets['ADMIN_PASSWORD'])
        ->and($argv)->toContain('--data-binary');

    expect($postedBody)->toContain('owner%40example.com')
        ->and($postedBody)->toContain(urlencode($application->fresh()->docker_secrets['ADMIN_PASSWORD']))
        // Set and non-blank, the controller registers the installation with ChatwootHub.
        ->and($postedBody)->not->toContain('subscribe_to_updates');
});

it('fails the provision when the app accepted the post and still has no owner', function () {
    // Chatwoot answers the same redirect whether it built the account or raised (e.g. a
    // disposable email), and shows the reason only in a flash its SPA never renders. The panel
    // cannot read the cause and must not call the site Active: until an owner exists, whoever
    // opens the URL can become one.
    $application = installDockerApp(dockerAppSite('chatwoot'));
    $application->forceFill([
        'app_port' => 3002,
        'settings' => ['admin_email' => 'someone@test.com'],
    ])->save();

    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') !== 'curl') {
            return Process::result(exitCode: 0);
        }

        // The post succeeds; the endpoint stays OPEN, because no owner was made.
        return in_array('POST', $args, true)
            ? Process::result(exitCode: 0)
            : Process::result(output: '200');
    });

    expect(fn () => app(DockerAppInstaller::class)->afterStart($application->fresh(), '/home/owner/site/public_html'))
        ->toThrow(ProvisioningFailedException::class);

    try {
        app(DockerAppInstaller::class)->afterStart($application->fresh(), '/home/owner/site/public_html');
    } catch (ProvisioningFailedException $e) {
        // Not `owner_not_created`: its text points to an application log that says nothing here.
        expect($e->reason)->toBe('claim_refused')
            ->and($e->step)->toBe('create_admin')
            ->and(__("application.failure_reason.{$e->reason}"))->toContain('disposable');
    }
});

it('is wired to the Docker installer, for each app', function (string $type, int $port, array $roles) {
    // Recipes resolve through InstallerManager's registry fallback. Without an installer the
    // site falls back to the GENERIC Docker driver, whose compose file has an empty `image:`
    // that `docker compose up` refuses. Tests calling the installer directly would not notice.
    expect(app(InstallerManager::class)->hasInstaller($type))
        ->toBeTrue("{$type} has no recipe installer — it will fall back to the generic Docker site")
        ->and(app(InstallerManager::class)->installerForType($type))
        ->toBeInstanceOf(DockerAppInstaller::class);
})->with('docker apps');

it('writes a compose file that parses, for each app', function (string $type, int $port, array $roles) {
    $application = installDockerApp(dockerAppSite($type));

    $parsed = Yaml::parse((string) $application->compose);

    expect($parsed)->toBeArray()
        // One service for a Group A app, two with its own database, four for Chatwoot. This is
        // only a sanity range; the two tests below pin the shape (one published port, and a
        // memory ceiling and bounded logs on EVERY service).
        ->and(count($parsed['services']))->toBeGreaterThanOrEqual(1)
        ->and(count($parsed['services']))->toBeLessThanOrEqual(4)
        // The app's own port is published; nginx proxies to the panel's allocated host port.
        ->and($application->container_port)->toBe($port);
})->with('docker apps');

it('publishes only to loopback, and only the app', function (string $type, int $port, array $roles) {
    // Docker writes its rules into the DOCKER chain ahead of ufw's, so `"20101:1337"` is
    // reachable from the internet while the Firewall page says the port is closed. The DATABASE
    // must not be published at all.
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    $published = collect($parsed['services'])
        ->filter(fn (array $service): bool => isset($service['ports']));

    expect($published)->toHaveCount(1);

    foreach ($published->first()['ports'] as $mapping) {
        expect($mapping)->toStartWith('127.0.0.1:');
    }
})->with('docker apps');

it('gives every service a memory ceiling and bounded logs', function (string $type, int $port, array $roles) {
    // A container with no ceiling can take the box down; Docker's default json-file driver has
    // no max size, so a chatty one fills the disk.
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    foreach ($parsed['services'] as $name => $service) {
        expect($service['mem_limit'] ?? null)->not->toBeNull("{$name} has no memory ceiling")
            ->and($service['logging']['options']['max-size'] ?? null)->not->toBeNull("{$name} has unbounded logs")
            ->and($service['restart'] ?? null)->toBe('unless-stopped');
    }
})->with('docker apps');

it('records the volumes so the panel owns them', function (string $type, int $port, array $roles) {
    // `volume_mounts` feeds the Docker page's Used-by column and both delete guards. Volumes are
    // declared `external: true` so Compose looks them up instead of creating its own empty one.
    $application = installDockerApp(dockerAppSite($type));
    $parsed = Yaml::parse((string) $application->compose);

    $names = collect($application->volume_mounts)->pluck('volume');

    expect($names)->toHaveCount(count($roles));

    foreach ($roles as $role) {
        expect($names->contains("sv-app-{$application->id}_{$role}"))->toBeTrue("missing volume for {$role}");
    }

    if ($roles === []) {
        // An empty `volumes:` key is not valid compose: the block must be absent.
        expect($parsed)->not->toHaveKey('volumes');

        return;
    }

    foreach ($parsed['volumes'] as $declared) {
        expect($declared['external'] ?? false)->toBeTrue();
    }
})->with('docker apps');

it('generates a different secret for every site', function (string $type, int $port, array $roles) {
    // A value in the template would be shared by every panel-installed copy of this app.
    $first = installDockerApp(dockerAppSite($type, 20101, 'first'));
    $second = installDockerApp(dockerAppSite($type, 20102, 'second'));

    $keys = app(SiteTypeManager::class)->find($type)->generatedSecrets();

    // Group A apps have no database and need no credential; an app WITH one must have secrets,
    // or its password is a literal in a template.
    if ($keys === []) {
        expect(count(Yaml::parse((string) $first->compose)['services']))->toBe(1);

        return;
    }

    // Panel-only secrets are used by the PANEL, not the file, so the reach-the-file assertion is
    // inverted: in the compose file they would be a credential with no reader, readable in the
    // File Manager.
    $panelOnly = app(SiteTypeManager::class)->find($type)->panelOnlySecrets();

    foreach ($keys as $key) {
        $a = $first->docker_secrets[$key] ?? null;
        $b = $second->docker_secrets[$key] ?? null;

        expect($a)->not->toBeNull("{$key} was not generated")
            ->and(strlen($a))->toBeGreaterThanOrEqual(24)
            ->and($a)->not->toBe($b, "{$key} is the same on two sites");

        expect(secretReachedFile((string) $first->compose, $a))->toBe(
            ! in_array($key, $panelOnly, true),
            in_array($key, $panelOnly, true)
                ? "{$key} is panel-only and must not be in the compose file"
                : "{$key} was stored but never reached the compose file",
        );
    }
})->with('docker apps');

it('ships no secret in the template itself', function (string $type, int $port, array $roles) {
    // Reads the template, not the render: a default that only shows when a value is not passed
    // is still a shipped credential.
    $siteType = app(SiteTypeManager::class)->find($type);
    expect($siteType)->toBeInstanceOf(RecipeSiteType::class);
    $source = $siteType->recipe()->template->source();
    expect($source)->not->toBeEmpty();

    $panelOnly = $siteType->panelOnlySecrets();

    foreach ($siteType->generatedSecrets() as $key) {
        $pattern = '/\{\{\s*secret\.'.preg_quote($key, '/').'\s*(?:\|\s*base64\s*)?\}\}/';
        if (in_array($key, $panelOnly, true)) {
            // Must be ABSENT, not interpolated — asserted above.
            expect($source)->not->toMatch($pattern);

            continue;
        }

        // Every secret is interpolated, never literal (including base64 APP_KEY).
        expect($source)->toMatch($pattern);
        expect($source)->not->toMatch('/'.preg_quote($key, '/').':\s*[A-Za-z0-9]{6,}\s*$/m');
    }
})->with('docker apps');

it('keeps the app and its database on separate credentials', function () {
    // Ghost must not connect as MySQL root: that credential would open every database on the server.
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite('ghost'))->compose);

    $db = $parsed['services']['db']['environment'];

    expect($db['MYSQL_ROOT_PASSWORD'])->not->toBe($db['MYSQL_PASSWORD'])
        ->and($parsed['services']['ghost']['environment']['database__connection__user'])->toBe('ghost')
        ->and($parsed['services']['ghost']['environment']['database__connection__user'])->not->toBe('root');
});

it('tells the app its own HTTPS url, when it takes one', function (string $type, int $port, array $roles) {
    // Ghost builds every link and redirect from this; a wrong value serves pages whose assets
    // point at another host. Null is a real answer, so the test asks the TYPE for its key
    // instead of guessing a name.
    $siteType = app(SiteTypeManager::class)->find($type);
    $key = $siteType->urlEnvKey();

    $application = installDockerApp(dockerAppSite($type));
    $parsed = Yaml::parse((string) $application->compose);
    $env = collect($parsed['services'])->first()['environment'] ?? [];

    if ($key === null) {
        // No URL under another spelling either: two sources for the URL would disagree.
        expect(collect($env)->keys()->filter(
            fn (string $name): bool => str_contains(strtolower($name), 'url')
        )->all())->toBe([]);

        return;
    }

    expect($env[$key] ?? null)->toBe('https://'.$application->domain);
})->with('docker apps');

it('survives the validator that every pasted compose goes through', function (string $type, int $port, array $roles) {
    // The rules enforced on a pasted file (no privileged, no host networking, bind mounts inside
    // the site directory) must hold for a file the panel WRITES too.
    $compose = (string) installDockerApp(dockerAppSite($type))->compose;

    $validator = new ComposeValidator(app(ServerOps::class));

    // The service-level rules are the ones that do not need Docker present.
    expect(fn () => $validator->validate($compose, '/home/owner/site/public_html'))->not->toThrow(Throwable::class);

    // And directly: nothing in these templates reaches for the host.
    expect($compose)->not->toContain('privileged')
        ->and($compose)->not->toContain('network_mode')
        ->and($compose)->not->toContain('/var/run/docker.sock');
})->with('docker apps');

it('is offered on a Docker box and refused on a LEMP one', function (string $type, int $port, array $roles) {
    // The stack gate comes from `servingProfile() === 'docker'`, mirroring how PHP one-clicks
    // are filtered off a Docker box.
    $manager = app(SiteTypeManager::class);

    expect(collect($manager->catalog())->pluck('name'))->toContain($type);

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true, 'serving_profiles' => ['php', 'node', 'static']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $fresh = app(SiteTypeManager::class);
    $card = collect($fresh->catalog())->firstWhere('name', $type);

    // Either absent from the grid or explicitly unavailable — never offered.
    expect($card === null || $card['available'] === false)->toBeTrue();
})->with('docker apps');

it('asks only how big to run it, because the app has answered everything else', function (string $type, int $port, array $roles) {
    // A field offering to change the image or port would break the compose file the panel
    // writes, so the field list is closed and the expectation is exact per app.
    // Size is the one deliberate exception: how much of this server the app may have is the
    // only question it cannot answer itself. Chatwoot also needs `admin_email`: its first
    // administrator cannot be passed as an env var (the seeds create no user), so the panel
    // claims the setup endpoint and needs an address for it.
    $expected = $type === 'chatwoot'
        ? ['memory_limit', 'cpu_limit', 'admin_email']
        : ['memory_limit', 'cpu_limit'];

    $siteType = app(SiteTypeManager::class)->find($type);

    expect($siteType)->toBeInstanceOf(AbstractDockerAppType::class)
        ->and(collect($siteType->fields())->pluck('name')->all())->toBe($expected)
        ->and($siteType->needsDatabase())->toBeFalse();
})->with('docker apps');

it('tells the user the memory figure their app actually needs', function (string $type, int $port, array $roles) {
    // The placeholder and help name the EFFECTIVE default: the app's declared floor where it has
    // one, the server default otherwise. Naming the server's 512m for Metabase would be too low
    // and make the app fail to start.
    $siteType = app(SiteTypeManager::class)->find($type);
    $expected = $siteType->defaultMemoryLimit() ?? (string) config('server.docker.default_memory_limit');

    $memory = collect($siteType->fields())->firstWhere('name', 'memory_limit');

    expect($memory['placeholder'])->toBe($expected)
        ->and($memory['help'])->toContain($expected)
        // Never pre-filled: a value in the field is a user choice, and the EMPTY state lets a
        // later change to the app floor reach a site that never overrode it.
        ->and($memory['default'] ?? null)->toBeNull();

    // An app with a measured floor says so; one without must not claim a measurement.
    expect(str_contains($memory['help'], 'measured'))
        ->toBe($siteType->defaultMemoryLimit() !== null, "{$type} misstates where its default comes from");
})->with('docker apps');

it('bounds a one-click cpu limit by the box, like every other form', function () {
    // Otherwise an over-provisioned one-click installs, fails at `compose up` and must be
    // recreated.
    app()->instance(HostCpus::class, new class extends HostCpus
    {
        public function count(): int
        {
            return 2;
        }
    });

    $rules = app(SiteTypeManager::class)->find('ghost')->rules();

    $validator = Validator::make(['cpu_limit' => '8', 'memory_limit' => '512'], $rules);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('cpu_limit'))->toContain('2 CPUs')
        // The unit trap is refused here too, with the message that names it.
        ->and($validator->errors()->first('memory_limit'))->toContain('bytes to Docker');
});

it('keeps the same secrets when the url changes', function (string $type, int $port, array $roles) {
    // Scanning the rendered file cannot recover a key the template renames (Ghost writes
    // `GHOST_DB_PASSWORD` into `database__connection__password`), so a re-render would rotate
    // the app's password but not MySQL's and the site could no longer reach its database.
    $application = installDockerApp(dockerAppSite($type));
    $before = $application->docker_secrets;

    app(DockerAppInstaller::class)->syncUrl($application, 'https://moved.example.com');

    $after = $application->fresh();

    expect($after->docker_secrets)->toBe($before);

    // A panel-only secret was never in the file; its survival is covered by the array comparison.
    $panelOnly = app(SiteTypeManager::class)->find($type)->panelOnlySecrets();

    foreach ($before as $key => $value) {
        if (in_array($key, $panelOnly, true)) {
            continue;
        }

        expect(secretReachedFile((string) $after->compose, $value))
            ->toBeTrue("{$key} stopped reaching the compose file when the url moved");
    }

    // The url moved, for apps that have one; an app whose URL lives in its own database has
    // nothing to move.
    if (app(SiteTypeManager::class)->find($type)->urlEnvKey() !== null) {
        expect($after->compose)->toContain('https://moved.example.com');
    } else {
        expect($after->compose)->toBe((string) $application->compose);
    }
})->with('docker apps');

it('stores the secrets encrypted, not as readable json', function () {
    // Live database credentials: the compose file on disk must hold them for Compose, but the
    // panel's own copy must not be readable.
    $application = installDockerApp(dockerAppSite('ghost'));

    $raw = (string) DB::table('applications')->where('id', $application->id)->value('docker_secrets');

    expect($raw)->not->toBeEmpty()
        ->and($raw)->not->toContain('MYSQL_ROOT_PASSWORD')
        ->and($raw)->not->toContain($application->docker_secrets['MYSQL_ROOT_PASSWORD']);
});

it('renders the memory ceiling the app actually needs', function (string $type, int $port, array $roles) {
    // Metabase and NocoDB crash under the panel's 512m default and fail as a 502 with nothing
    // about memory in the panel.
    $siteType = app(SiteTypeManager::class)->find($type);
    $expected = $siteType->defaultMemoryLimit() ?? config('server.docker.default_memory_limit');

    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    // The app's own service, not the database (which keeps the db default).
    $app = $parsed['services'][array_key_first($parsed['services'])];

    expect($app['mem_limit'])->toBe($expected);
})->with('docker apps');

it('lets the user override the app floor', function () {
    // The declared limit is what the app needs to start, not a cap: a user who set 4g keeps it.
    $application = dockerAppSite('metabase');
    $application->forceFill(['memory_limit' => '4g'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['metabase']['mem_limit'])->toBe('4g');
});

it('keeps the same secrets when installed twice', function (string $type, int $port, array $roles) {
    // Retry Setup and re-provision call `install()` again. Generating afresh would rotate the
    // credential in the compose file while the database keeps the one it was initialised with:
    // `POSTGRES_PASSWORD` and `MYSQL_*` apply only to an EMPTY data directory.
    $application = dockerAppSite($type);

    $first = installDockerApp($application)->docker_secrets;
    $second = installDockerApp($application->fresh())->docker_secrets;

    expect($second)->toBe($first);
})->with('docker apps');

it('still generates a secret the app has gained since install', function () {
    // The union must keep stored values AND fill gaps: `$stored + $fresh` does, `$fresh + $stored`
    // rotates everything.
    $application = installDockerApp(dockerAppSite('ghost'));

    $partial = $application->docker_secrets;
    unset($partial['GHOST_DB_PASSWORD']);
    $application->forceFill(['docker_secrets' => $partial])->save();

    $after = installDockerApp($application->fresh())->docker_secrets;

    expect($after['MYSQL_ROOT_PASSWORD'])->toBe($partial['MYSQL_ROOT_PASSWORD'])
        ->and($after['GHOST_DB_PASSWORD'] ?? null)->not->toBeNull();
});

it('creates the directory before writing into it', function () {
    // `ManagedFile::put()` is only `tee <path>`, so a write into a missing directory fails and the
    // container crash-loops with no config.
    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = $args;

        if (($args[0] ?? '') === 'cat') {
            return Process::result(exitCode: 1, errorOutput: 'No such file');
        }

        return Process::result(exitCode: 0);
    });

    installDockerApp(dockerAppSite('glance'));

    $index = fn (string $binary): int|false => collect($ran)->search(
        fn (array $args): bool => ($args[0] ?? '') === $binary
    );

    // Order is the whole assertion: mkdir, then tee, then chown. `tee` runs elevated, so a chown
    // before it leaves the FILE root-owned, which the File Manager cannot edit.
    expect($index('mkdir'))->not->toBeFalse('nothing created the config directory')
        ->and($index('tee'))->not->toBeFalse()
        ->and($index('chown'))->not->toBeFalse('the file was left root-owned')
        ->and($index('mkdir'))->toBeLessThan($index('tee'))
        ->and($index('tee'))->toBeLessThan($index('chown'));
});

it('fails provisioning when the starter file cannot be written', function () {
    // Ignoring `put()`'s result made a failed write silent: provisioning reported success with
    // no config.
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat') {
            return Process::result(exitCode: 1, errorOutput: 'No such file');
        }

        if (($args[0] ?? '') === 'tee') {
            return Process::result(exitCode: 1, errorOutput: 'Permission denied');
        }

        return Process::result(exitCode: 0);
    });

    expect(fn () => installDockerApp(dockerAppSite('glance')))
        ->toThrow(ProvisioningFailedException::class);
});

it('writes the files an app cannot start without', function () {
    // Glance exits on boot with `reading /app/config/glance.yml: no such file or directory`, and a
    // named volume starts empty, so an empty directory is not a usable start.
    $written = [];
    Process::fake(function ($process) use (&$written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        // `cat` is the existence probe; make it fail so the write happens.
        if (($args[0] ?? '') === 'cat') {
            return Process::result(exitCode: 1, errorOutput: 'No such file');
        }

        $written[] = $args;

        return Process::result(exitCode: 0);
    });

    $application = installDockerApp(dockerAppSite('glance'));
    $compose = (string) $application->compose;

    // Bind-mounted from inside the site directory: ComposeValidator permits it and the File
    // Manager becomes the editor.
    expect($compose)->toContain(':/app/config')
        ->and($compose)->toContain('/home/owner/site/public_html/app/config')
        // And a named volume is NOT used for it.
        ->and($application->volume_mounts)->toBe([]);

    // The compose file alone would pass while nothing created the config.
    expect(collect($written)->contains(
        fn (array $args): bool => collect($args)->contains(fn ($a) => str_contains((string) $a, 'glance.yml'))
    ))->toBeTrue('nothing wrote glance.yml');
});

it('does not overwrite a config somebody has edited', function () {
    // A reinstall or Retry Setup must leave an edited configuration alone, like the secrets.
    $writes = 0;
    Process::fake(function ($process) use (&$writes) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        // The probe succeeds: the file is already there.
        if (($args[0] ?? '') === 'cat') {
            return Process::result(output: 'pages: [] # mine');
        }

        if (collect($args)->contains(fn ($a) => str_contains((string) $a, 'glance.yml'))) {
            $writes++;
        }

        return Process::result(exitCode: 0);
    });

    installDockerApp(dockerAppSite('glance'));

    expect($writes)->toBe(0);
});

it('tells Homepage which host it is served on', function () {
    // Homepage answers an unknown Host header with a 400: the container looks healthy but the
    // site is unusable.
    $application = installDockerApp(dockerAppSite('homepage'));

    expect(Yaml::parse((string) $application->compose)['services']['app']['environment'])
        ->toHaveKey('HOMEPAGE_ALLOWED_HOSTS', $application->domain);
});

/*
 * Grafana and BookStack: behaviour the shared property tests above cannot see.
 */

it('never leaves Grafana on admin/admin', function () {
    // Grafana's default admin/admin on a public URL is a race with everyone else, so the panel
    // sets a password per site. `GF_SECURITY_ADMIN_PASSWORD` is read on every boot, so this value
    // IS the password, not a seed.
    $application = installDockerApp(dockerAppSite('grafana', 20101));

    $parsed = Yaml::parse((string) $application->compose);
    $environment = $parsed['services']['app']['environment'];

    $secret = $application->docker_secrets['GF_SECURITY_ADMIN_PASSWORD'];

    expect($environment['GF_SECURITY_ADMIN_PASSWORD'])->toBe($secret)
        ->and($secret)->not->toBe('admin')
        ->and(strlen($secret))->toBeGreaterThanOrEqual(24)
        // The account is named, so the Credentials panel and the login form agree.
        ->and($environment['GF_SECURITY_ADMIN_USER'])->toBe('admin');
});

it('does not quietly make every Grafana dashboard public', function () {
    // Anonymous access would turn "install Grafana" into "publish my metrics", and the reporting
    // toggles phone home. Neither may be enabled silently.
    $application = installDockerApp(dockerAppSite('grafana', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    expect($environment['GF_AUTH_ANONYMOUS_ENABLED'])->toBe('false')
        ->and($environment['GF_ANALYTICS_REPORTING_ENABLED'])->toBe('false');
});

it('gives BookStack a key Laravel will actually accept', function () {
    // `APP_KEY` must be `base64:` plus exactly 32 base64-encoded bytes; passed raw, BookStack
    // exits at boot with an "unsupported cipher" error that does not name the key.
    $application = installDockerApp(dockerAppSite('bookstack', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    $key = $environment['APP_KEY'];

    expect($key)->toStartWith('base64:');

    $decoded = base64_decode(substr($key, strlen('base64:')), true);

    // Assert 32 BYTES after decoding (what the cipher requires), not the string length: a
    // 32-character base64 string decodes to 24 bytes and would look right.
    expect($decoded)->not->toBeFalse()
        ->and(strlen($decoded))->toBe(32)
        ->and($decoded)->toBe($application->docker_secrets['APP_KEY']);
});

it('does not mount the database volume into the BookStack container', function () {
    // `$mounts` holds every volume the type declares, so looping it in a multi-service file would
    // hand MariaDB's data directory to the application as well.
    $application = installDockerApp(dockerAppSite('bookstack', 20101));
    $parsed = Yaml::parse((string) $application->compose);

    expect($parsed['services']['app']['volumes'])->toBe(['sv-app-'.$application->id.'_config:/config'])
        ->and($parsed['services']['db']['volumes'])->toBe(['sv-app-'.$application->id.'_db:/var/lib/mysql']);
});

it('tells BookStack its url, which it refuses to start without', function () {
    $application = installDockerApp(dockerAppSite('bookstack', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    expect($environment['APP_URL'])->toStartWith('https://')
        // The database host is the compose service name, not localhost: the two containers
        // share only a network.
        ->and($environment['DB_HOST'])->toBe('db');
});

it('gives every Docker app an explicitly tagged image', function () {
    // An untagged reference is `latest` by omission, which nobody reading the recipe would notice.
    // An explicit `latest` is allowed: Metabase, NocoDB and BookStack use it deliberately
    // because upstream publishes only exact versions and `latest`.
    // This cannot check that the tag EXISTS; that needs the registry.
    $recipes = app(RecipeRegistry::class)->all();
    expect($recipes)->not->toBeEmpty();

    foreach ($recipes as $recipe) {
        foreach ($recipe->images as $role => $image) {
            expect($image['ref'])->not->toBe('')
                ->and(str_contains($image['ref'], ':'))
                ->toBeTrue("{$recipe->slug}.{$role} has no explicit tag");
        }
    }
});

/*
 * The container WordPress.
 */

it('teaches WordPress that the request arrived over HTTPS', function () {
    // nginx terminates TLS and proxies over plain HTTP, so PHP sees no `HTTPS` in `$_SERVER`.
    // WordPress then writes `http://` asset URLs (mixed content), and if `siteurl` says `https`
    // every request becomes a redirect loop. The container vhost sends the header; this reads it.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));
    $extra = Yaml::parse((string) $application->compose)['services']['app']['environment']['WORDPRESS_CONFIG_EXTRA'];

    // Escaped as `$$`, which reaches PHP as one `$`. Written plainly, Compose interpolates it away,
    // wp-config's eval() dies and every request is a 500.
    expect($extra)->toContain('HTTP_X_FORWARDED_PROTO')
        ->and($extra)->toContain("\$\$_SERVER['HTTPS'] = 'on'")
        ->and($extra)->not->toContain("\n    \$_SERVER");
});

it('makes the panel domain the one source for the WordPress url', function () {
    // WordPress normally owns `siteurl` in its database, so a panel-side domain change leaves it
    // redirecting to the old host. WP_HOME and WP_SITEURL come from the environment instead.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    expect($environment['WORDPRESS_SITE_URL'])->toBe('https://'.$application->domain)
        ->and($environment['WORDPRESS_CONFIG_EXTRA'])->toContain("define('WP_HOME'")
        ->and($environment['WORDPRESS_CONFIG_EXTRA'])->toContain("define('WP_SITEURL'");
});

it('follows a domain change through to the WordPress url', function () {
    // `urlEnvKey()` must be a real variable: `syncUrl()` returns early for a type that declares
    // none, so the baked URL would go stale.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));

    app(DockerAppInstaller::class)->syncUrl($application, 'https://moved.example.com');

    $environment = Yaml::parse((string) $application->fresh()->compose)['services']['app']['environment'];

    expect($environment['WORDPRESS_SITE_URL'])->toBe('https://moved.example.com');
});

it('does not collide with the PHP WordPress it sits beside', function () {
    // `name()` is the identifier (`find()` and SiteTypeText resolve by it), so two types called
    // `wordpress` would shadow each other.
    $php = app(SiteTypeManager::class)->find('wordpress');
    $container = app(SiteTypeManager::class)->find('wordpress_container');

    expect($php)->not->toBeNull()->and($container)->not->toBeNull()
        ->and($php->servingProfile())->toBe('php')
        ->and($container->servingProfile())->toBe('docker')
        // Same title is fine: each is filtered out of the other's stack.
        ->and(app(SiteTypeText::class)->title($php->name()))->toBe('WordPress')
        ->and(app(SiteTypeText::class)->title($container->name()))->toBe('WordPress');
});

it('leaves the WordPress salts to the image', function () {
    // The entrypoint generates the eight keys from /dev/urandom when absent and writes them into
    // wp-config.php in the volume, so they are already unique per site and stable.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    foreach (['WORDPRESS_AUTH_KEY', 'WORDPRESS_SECURE_AUTH_KEY', 'WORDPRESS_NONCE_SALT'] as $key) {
        expect($environment)->not->toHaveKey($key);
    }
});

it('escapes every dollar sign, because Compose interpolates its own file', function (string $type, int $port, array $roles) {
    // A general guard. Compose silently substitutes `$NAME` and `${NAME}` anywhere in the file
    // (an unset variable becomes an empty string), so a template that writes a shell or PHP
    // variable must double the dollar.
    $compose = (string) installDockerApp(dockerAppSite($type, $port))->compose;

    // A lone `$` that starts an identifier or a brace: exactly what Compose would consume.
    expect(preg_match('/(?<!\$)\$(?!\$)[A-Za-z_{]/', $compose))
        ->toBe(0, "{$type} writes an unescaped \$variable that Compose will interpolate away");
})->with('docker apps');

/*
 * Per-app CPU quotas.
 *
 * Deliberately NOT symmetric with memory. A memory floor is a fact about the software (Metabase
 * needs 2g), but an app is only slower on less CPU and does not fail, so a CPU floor would be
 * invented policy. A CPU *default* would also throttle every installed one-click app on its
 * next deploy.
 */
it('gives a one-click app no cpu quota unless the user set one', function (string $type, int $port, array $roles) {
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    foreach ($parsed['services'] as $name => $service) {
        expect($service)->not->toHaveKey('cpus', "{$name} was given a CPU quota nobody asked for");
    }
})->with('docker apps');

it('applies the user cpu quota to the app service only', function () {
    // Splitting the quota with a bundled database would mean guessing a ratio, wrong for every app
    // whose work is in its database, so the engine stays unbounded.
    $application = dockerAppSite('ghost');
    $application->forceFill(['cpu_limit' => '1.5'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['ghost']['cpus'])->toBe(1.5)
        ->and($parsed['services']['ghost_db'] ?? $parsed['services'][array_key_last($parsed['services'])])
        ->not->toHaveKey('cpus');
});

it('renders a fractional cpu quota that parses as a number', function () {
    // `cpus: 0.5` must be read back as a number by Compose's parser. Quoted or malformed it is a
    // string, and Compose refuses the file at `up`, after the site was saved.
    $application = dockerAppSite('metabase');
    $application->forceFill(['cpu_limit' => '0.5'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['metabase']['cpus'])->toBe(0.5);
});

it('does not let the app ceiling become the database ceiling', function () {
    // A one-click's `compose` column holds a panel-rendered file, so
    // `ContainerSupervisor::contents()` takes its pasted-file branch and writes an override for
    // every service. That override exists to give a HAND-WRITTEN file limits it lacks; applied to
    // the panel's own template it replaces per-service numbers that were already right, and
    // `docker inspect` shows the app's ceiling on BOTH services.
    $application = dockerAppSite('ghost');
    $application->forceFill(['memory_limit' => '640m', 'cpu_limit' => '0.5'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['ghost']['mem_limit'])->toBe('640m')
        ->and($parsed['services']['ghost']['cpus'])->toBe(0.5)
        // The database keeps its own ceiling and takes no quota, as the create form's help promises.
        ->and($parsed['services']['db']['mem_limit'])->toBe((string) config('server.docker.default_db_memory_limit'))
        ->and($parsed['services']['db'])->not->toHaveKey('cpus');
});
