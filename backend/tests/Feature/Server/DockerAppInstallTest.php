<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\AbstractDockerAppType;
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
 * One-click Docker applications — Ghost and Strapi.
 *
 * The panel shipped sixteen one-click types and every one installed on the HOST
 * as PHP or Node, so a Docker-stack box had none at all: you typed an image or
 * pasted YAML. These are the first two that are containers.
 *
 * What these tests are really about is the compose file the panel writes on
 * somebody's behalf. Three properties it must have, and none of them are visible
 * from "the site came up":
 *
 *  - Secrets are GENERATED per site. A value in a template would be shared by
 *    every panel-installed copy of that app in the world.
 *  - The hardening the generic template carries — loopback-only publishing, a
 *    memory ceiling, bounded logs — is present in every service of every app.
 *  - The database is not published to the host.
 */
beforeEach(function () {
    // A permissive default, so a test about the rendered compose file does not
    // also have to describe the box. `cat` fails because it is the existence
    // probe for starter files and they should be written; everything else
    // succeeds. Individual tests re-fake when the box is what they are about.
    //
    // This was absent, and the dataset tests passed only because a failed write
    // was being ignored — the same silence that let Glance ship without a config.
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
        'domain' => "{$type}-{$port}.example.com",
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
 * Verbatim for every app but one. BookStack's `APP_KEY` is a Laravel application
 * key, which has a format: `base64:` followed by 32 base64-encoded bytes. The
 * generator produces a 32-character string — 32 bytes — and the template encodes
 * it, because passing it raw makes BookStack exit at boot complaining about an
 * unsupported cipher.
 *
 * So the property is "the value the panel stored is the value the app receives",
 * and for one app the transport is base64. Checked as an alternative rather than
 * loosened to a substring match, which would have passed for a truncated or
 * re-generated key too.
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
    // Group A: one container each, rendered from the shared template.
    'vaultwarden' => ['vaultwarden', 80, ['data']],
    'gitea' => ['gitea', 3000, ['data']],
    'forgejo' => ['forgejo', 3000, ['data']],
    'freshrss' => ['freshrss', 80, ['data']],
    'stirlingpdf' => ['stirlingpdf', 8080, ['data']],
    'ittools' => ['ittools', 80, []],
    // No named volume: Glance's config is a bind mount inside the site directory,
    // because it is a file somebody edits. See the starter-files tests below.
    'glance' => ['glance', 8080, []],
    'homepage' => ['homepage', 3000, ['config']],
    // Grafana is one container, but not from the shared template: its admin
    // password is a generated SECRET, and that template renders `$environment`
    // only. See GrafanaSiteType::composeTemplate().
    'grafana' => ['grafana', 3000, ['data']],
    'bookstack' => ['bookstack', 80, ['config', 'db']],
    'wordpress_container' => ['wordpress_container', 80, ['app', 'db']],
    // Four services, which nothing else here has: a Rails app, a Sidekiq worker
    // that every email and webhook depends on, pgvector and Redis.
    'chatwoot' => ['chatwoot', 3000, ['storage', 'db', 'redis']],
    // Nothing to persist at all — a static bundle, like IT-Tools.
    'excalidraw' => ['excalidraw', 80, []],
    // Strapi was the intended second app and publishes NO official image —
    // `strapi/strapi` and `strapi/base` are both gone from Docker Hub, and
    // upstream's own guidance is to build your own from a `create-strapi-app`
    // project. Verified against the registry, not assumed. A one-click that
    // pulls a third-party rebuild is not something a server panel should ship,
    // so the second app is a decision still to be made and the dataset has one
    // entry rather than a card that 404s at `docker pull`.
]);

it('generates a password that satisfies a policy, not merely a random one', function () {
    // Measured against a running Chatwoot: an alphanumeric password creates no
    // user, and the onboarding controller answers the same redirect whether it
    // built the account or swallowed the exception into a flash message. So the
    // only symptom of the default `Str::random(32)` was a site that never got an
    // owner, found by counting rows in its database.
    //
    // Run repeatedly because the guarantee is meant to be structural: drawing 32
    // random alphanumerics and hoping for an upper-case letter is a test that
    // passes almost always, which is worse than one that fails.
    foreach (range(1, 40) as $run) {
        // A distinct port and system user per run: the helper creates one, and
        // the username is unique.
        $password = installDockerApp(dockerAppSite('chatwoot', 20100 + $run, "owner{$run}"))
            ->docker_secrets['ADMIN_PASSWORD'];

        expect(strlen($password))->toBeGreaterThanOrEqual(24)
            ->and($password)->toMatch('/[A-Z]/')
            ->and($password)->toMatch('/[a-z]/')
            ->and($password)->toMatch('/[0-9]/')
            ->and($password)->toMatch('/[!@#%^*\-_=+]/');
    }

    // And the apps that declare no policy are untouched — a symbol in a password
    // an app reads from its environment is a quoting bug waiting to happen.
    expect(installDockerApp(dockerAppSite('grafana', 20199, 'gfowner'))->docker_secrets['GF_SECURITY_ADMIN_PASSWORD'])
        ->toMatch('/^[A-Za-z0-9]+$/');
});

it('claims the first-run endpoint, and survives being called at all', function () {
    // Two things, and the second is the embarrassing one.
    //
    // The flow: the endpoint answers 200 while the setup page is open, the panel
    // posts the generated credentials, and the controller closes it — so the
    // re-check sees a redirect. If it still answers 200 the owner was not created
    // and provisioning must fail rather than report a claimable site as Active.
    //
    // And: that `afterStart()` can be CALLED. It shipped calling `$this->run()`,
    // copied from N8nInstaller, which has that helper from AbstractSiteInstaller —
    // a parent this class does not have. Nothing caught it, because no test
    // reached the method and `phpstan analyse` produces no output on this box, so
    // the first thing to run the line was a real provision.
    $application = installDockerApp(dockerAppSite('chatwoot'));
    $application->forceFill([
        'app_port' => 3002,
        'settings' => ['admin_email' => 'owner@example.com'],
    ])->save();

    $posted = null;
    $checks = 0;

    Process::fake(function ($process) use (&$posted, &$checks) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') !== 'curl') {
            return Process::result(exitCode: 0);
        }

        // The POST carries `-X POST`; the probe does not.
        if (in_array('POST', $args, true)) {
            $posted = $args;

            return Process::result(exitCode: 0);
        }

        // Open first, closed once the post has happened — a fake that always
        // answered 200 would assert against a server this fake had broken.
        $checks++;

        return Process::result(output: $posted === null ? '200' : '302');
    });

    app(DockerAppInstaller::class)->afterStart($application->fresh(), '/home/owner/site/public_html');

    expect($posted)->not->toBeNull('the first-run endpoint was never claimed')
        ->and(implode(' ', $posted))->toContain('/installation/onboarding')
        ->and(implode(' ', $posted))->toContain('user[email]=owner@example.com')
        // Set and non-blank, the controller registers the installation with
        // ChatwootHub — someone else's server learning about this one.
        ->and(implode(' ', $posted))->not->toContain('subscribe_to_updates')
        ->and($checks)->toBeGreaterThanOrEqual(2);
});

it('is wired to the Docker installer, for each app', function (string $type, int $port, array $roles) {
    // **The test that was missing, and what it cost.** Registering an app takes
    // three edits to `config/server.php`: the type class, the `docker_apps`
    // definition, and an `installers.<name>.driver` entry. Chatwoot and
    // Excalidraw shipped with the first two and not the third. Every test in this
    // file still passed, because `installDockerApp()` calls the installer
    // directly — so the suite proved the file renders and never asked whether
    // anything would render it.
    //
    // On a real box the site fell back to the GENERIC Docker driver, which wrote
    // a compose file with an empty `image:` and a bind mount of the site
    // directory. `docker compose up` refused it: "services.app.image must be a
    // string". An app installed from the catalog, failing on an empty image.
    expect(app(InstallerManager::class)->hasInstaller($type))
        ->toBeTrue("{$type} has no installers.{$type}.driver — it will fall back to the generic Docker site")
        ->and(app(InstallerManager::class)->installerForType($type))
        ->toBeInstanceOf(DockerAppInstaller::class);
})->with('docker apps');

it('writes a compose file that parses, for each app', function (string $type, int $port, array $roles) {
    $application = installDockerApp(dockerAppSite($type));

    $parsed = Yaml::parse((string) $application->compose);

    expect($parsed)->toBeArray()
        // One service for a Group A app, two for an app with its own database,
        // four for Chatwoot — a web container, a worker, pgvector and Redis.
        //
        // The ceiling was 2 until Chatwoot, and raising it costs nothing that was
        // being protected: this was always a sanity range, and the shape is
        // actually pinned by the two tests below — exactly one service publishes a
        // port, and EVERY service carries a memory ceiling and bounded logs.
        // Those hold a four-service app to the same rules as a one-service one.
        ->and(count($parsed['services']))->toBeGreaterThanOrEqual(1)
        ->and(count($parsed['services']))->toBeLessThanOrEqual(4)
        // The app's own port is published, and the panel's allocated host port
        // is what nginx proxies to.
        ->and($application->container_port)->toBe($port);
})->with('docker apps');

it('publishes only to loopback, and only the app', function (string $type, int $port, array $roles) {
    // Docker writes its own rules into the DOCKER chain ahead of ufw's, so
    // `"20101:1337"` is reachable from the internet while the Firewall page says
    // the port is closed. And the DATABASE must not be published at all — one per
    // Ghost site on the server's loopback is a MySQL nobody is watching.
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    $published = collect($parsed['services'])
        ->filter(fn (array $service): bool => isset($service['ports']));

    expect($published)->toHaveCount(1);

    foreach ($published->first()['ports'] as $mapping) {
        expect($mapping)->toStartWith('127.0.0.1:');
    }
})->with('docker apps');

it('gives every service a memory ceiling and bounded logs', function (string $type, int $port, array $roles) {
    // The partial exists so an app template cannot quietly ship without these.
    // A container with no ceiling can take the box down; Docker's default
    // json-file driver has no max size, so a chatty one fills the disk.
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    foreach ($parsed['services'] as $name => $service) {
        expect($service['mem_limit'] ?? null)->not->toBeNull("{$name} has no memory ceiling")
            ->and($service['logging']['options']['max-size'] ?? null)->not->toBeNull("{$name} has unbounded logs")
            ->and($service['restart'] ?? null)->toBe('unless-stopped');
    }
})->with('docker apps');

it('records the volumes so the panel owns them', function (string $type, int $port, array $roles) {
    // `volume_mounts` is what puts them on the Docker page with a Used-by column
    // and what both delete guards read. Declared `external: true` in the file so
    // Compose looks them up rather than making its own — without that, a site
    // comes up with an empty volume while the real one sits unreferenced.
    $application = installDockerApp(dockerAppSite($type));
    $parsed = Yaml::parse((string) $application->compose);

    $names = collect($application->volume_mounts)->pluck('volume');

    expect($names)->toHaveCount(count($roles));

    foreach ($roles as $role) {
        expect($names->contains("sv-app-{$application->id}_{$role}"))->toBeTrue("missing volume for {$role}");
    }

    if ($roles === []) {
        // IT-Tools stores nothing. An empty `volumes:` key is not valid compose,
        // so the block has to be absent rather than present and empty.
        expect($parsed)->not->toHaveKey('volumes');

        return;
    }

    foreach ($parsed['volumes'] as $declared) {
        expect($declared['external'] ?? false)->toBeTrue();
    }
})->with('docker apps');

it('generates a different secret for every site', function (string $type, int $port, array $roles) {
    // THE test. A value in the template would be shared by every panel-installed
    // copy of this app on every server.
    $first = installDockerApp(dockerAppSite($type, 20101, 'first'));
    $second = installDockerApp(dockerAppSite($type, 20102, 'second'));

    $keys = app(SiteTypeManager::class)->find($type)->generatedSecrets();

    // Group A apps bring no database and so need no credential. That is a real
    // answer, not an oversight — but an app WITH a database must have secrets, or
    // its password is a literal in a template.
    if ($keys === []) {
        expect(count(Yaml::parse((string) $first->compose)['services']))->toBe(1);

        return;
    }

    // Declared by the type: a secret the PANEL uses rather than the file. The
    // reach-the-file assertion is inverted for these — in the compose file they
    // would be a credential with no reader, published where the File Manager can
    // open it. Chatwoot's admin password is the only one.
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
    // Reading the template, not the render: a default that only shows up when a
    // caller forgets to pass a value is still a shipped credential.
    $template = app(SiteTypeManager::class)->find($type)->composeTemplate();
    $path = base_path('resources/views/'.str_replace('.', '/', $template).'.blade.php');

    $source = file_get_contents($path);

    $panelOnly = app(SiteTypeManager::class)->find($type)->panelOnlySecrets();

    foreach (app(SiteTypeManager::class)->find($type)->generatedSecrets() as $key) {
        if (in_array($key, $panelOnly, true)) {
            // Must be ABSENT, not interpolated — asserted above.
            expect($source)->not->toContain("\$secrets['{$key}']");

            continue;
        }

        // Every secret is interpolated, never literal.
        expect($source)->toContain("\$secrets['{$key}']");
        expect($source)->not->toMatch('/'.preg_quote($key, '/').':\s*[A-Za-z0-9]{6,}\s*$/m');
    }
})->with('docker apps');

it('keeps the app and its database on separate credentials', function () {
    // The hand-written file this was derived from had Ghost connecting as MySQL
    // root — so the app's credential was also the credential for every database
    // on that server's MySQL.
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite('ghost'))->compose);

    $db = $parsed['services']['db']['environment'];

    expect($db['MYSQL_ROOT_PASSWORD'])->not->toBe($db['MYSQL_PASSWORD'])
        ->and($parsed['services']['ghost']['environment']['database__connection__user'])->toBe('ghost')
        ->and($parsed['services']['ghost']['environment']['database__connection__user'])->not->toBe('root');
});

it('tells the app its own HTTPS url, when it takes one', function (string $type, int $port, array $roles) {
    // Ghost builds every link and redirect from this: wrong, and the site serves
    // pages whose assets point at another host, which reads as a broken theme
    // rather than a stale setting.
    //
    // Null is a real answer, and the test asks the TYPE rather than guessing a key
    // name — guessing is what this did first, and it failed on the second app by
    // looking for `URL` in a file that never had one.
    $siteType = app(SiteTypeManager::class)->find($type);
    $key = $siteType->urlEnvKey();

    $application = installDockerApp(dockerAppSite($type));
    $parsed = Yaml::parse((string) $application->compose);
    $env = collect($parsed['services'])->first()['environment'] ?? [];

    if ($key === null) {
        // And it must not carry one under some other spelling either, or the app
        // has two sources for its URL and they will disagree.
        expect(collect($env)->keys()->filter(
            fn (string $name): bool => str_contains(strtolower($name), 'url')
        )->all())->toBe([]);

        return;
    }

    expect($env[$key] ?? null)->toBe('https://'.$application->domain);
})->with('docker apps');

it('survives the validator that every pasted compose goes through', function (string $type, int $port, array $roles) {
    // The panel's own rules — no privileged, no host networking, bind mounts
    // inside the site directory — are enforced on a pasted file. A file the panel
    // WRITES must pass the same ones, or the panel is exempting itself.
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
    // The stack gate, which these get for free from `servingProfile() === 'docker'`
    // — the mirror of how the PHP one-clicks are filtered off a Docker box.
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
    // `DockerSiteType` asks for an image and a port because the user is choosing
    // what to run. Here a field offering to change either would be offering to
    // break the compose file the panel is about to write — so the list is still
    // closed, and this test is what keeps it closed.
    //
    // **Size is the exception, and it is one on purpose.** The image is a fact
    // about the software; how much of this particular server it may have is not,
    // so it is the only question the app cannot answer on the user's behalf. This
    // asserted `[]` until the two limit fields were added, and loosening it to
    // "some fields" would have thrown away the guarantee it exists for.
    // **The second exception, named rather than allowed.** Chatwoot's first
    // administrator cannot be passed in as an environment variable the way every
    // other app's is — its production seeds create no user, only a flag that opens
    // a setup page to whoever asks first — so the panel claims that endpoint and
    // needs an address to claim it for. Still a closed list: the expectation is
    // exact per app, so a third field on any app is a failure here.
    $expected = $type === 'chatwoot'
        ? ['memory_limit', 'cpu_limit', 'admin_email']
        : ['memory_limit', 'cpu_limit'];

    $siteType = app(SiteTypeManager::class)->find($type);

    expect($siteType)->toBeInstanceOf(AbstractDockerAppType::class)
        ->and(collect($siteType->fields())->pluck('name')->all())->toBe($expected)
        ->and($siteType->needsDatabase())->toBeFalse();
})->with('docker apps');

it('tells the user the memory figure their app actually needs', function (string $type, int $port, array $roles) {
    // The placeholder and the help name the EFFECTIVE default — the app's declared
    // floor where it has one, the server default where it does not. Naming the
    // server's 512m to somebody installing Metabase would be wrong in the
    // direction that makes the app fail to start: it reported 123.8 MB to the JVM
    // inside that ceiling and wrote a crash log, as a 502 with nothing about
    // memory in the panel.
    $siteType = app(SiteTypeManager::class)->find($type);
    $expected = $siteType->defaultMemoryLimit() ?? (string) config('server.docker.default_memory_limit');

    $memory = collect($siteType->fields())->firstWhere('name', 'memory_limit');

    expect($memory['placeholder'])->toBe($expected)
        ->and($memory['help'])->toContain($expected)
        // Never pre-filled. A value in the field is a value the user chose, and
        // the app floor applying is the EMPTY state — which is what lets a later
        // change to that floor reach a site that never overrode it.
        ->and($memory['default'] ?? null)->toBeNull();

    // An app with a measured floor says so; one without it must not claim a
    // measurement it does not have.
    expect(str_contains($memory['help'], 'measured'))
        ->toBe($siteType->defaultMemoryLimit() !== null, "{$type} misstates where its default comes from");
})->with('docker apps');

it('bounds a one-click cpu limit by the box, like every other form', function () {
    // Otherwise an over-provisioned one-click is a site that installs, fails at
    // `compose up`, and has to be deleted and created again.
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
        // And the unit trap is refused here too, with the message that names it.
        ->and($validator->errors()->first('memory_limit'))->toContain('bytes to Docker');
});

it('keeps the same secrets when the url changes', function (string $type, int $port, array $roles) {
    // The bug this column exists to prevent. Recovering secrets by scanning the
    // rendered file finds nothing for a key the template renames — Ghost writes
    // `GHOST_DB_PASSWORD` into `database__connection__password` — so a re-render
    // would rotate the app's password and not MySQL's, and the site would come
    // back up unable to reach its own database.
    $application = installDockerApp(dockerAppSite($type));
    $before = $application->docker_secrets;

    app(DockerAppInstaller::class)->syncUrl($application, 'https://moved.example.com');

    $after = $application->fresh();

    expect($after->docker_secrets)->toBe($before);

    // A panel-only secret was never in the file, so "still there" is the wrong
    // question for it; that it survived the re-render at all is asserted above,
    // where the whole array is compared.
    $panelOnly = app(SiteTypeManager::class)->find($type)->panelOnlySecrets();

    foreach ($before as $key => $value) {
        if (in_array($key, $panelOnly, true)) {
            continue;
        }

        expect(secretReachedFile((string) $after->compose, $value))
            ->toBeTrue("{$key} stopped reaching the compose file when the url moved");
    }

    // And the url really did move — for the apps that have one. An app whose URL
    // lives in its own database has nothing to move, and asserting otherwise
    // would be asserting that a no-op did something.
    if (app(SiteTypeManager::class)->find($type)->urlEnvKey() !== null) {
        expect($after->compose)->toContain('https://moved.example.com');
    } else {
        expect($after->compose)->toBe((string) $application->compose);
    }
})->with('docker apps');

it('stores the secrets encrypted, not as readable json', function () {
    // These are live database credentials. The compose file on disk holds them
    // because Compose has to read it; the panel's own copy has no such excuse.
    $application = installDockerApp(dockerAppSite('ghost'));

    $raw = (string) DB::table('applications')->where('id', $application->id)->value('docker_secrets');

    expect($raw)->not->toBeEmpty()
        ->and($raw)->not->toContain('MYSQL_ROOT_PASSWORD')
        ->and($raw)->not->toContain($application->docker_secrets['MYSQL_ROOT_PASSWORD']);
});

it('renders the memory ceiling the app actually needs', function (string $type, int $port, array $roles) {
    // Measured on a real box: Metabase inside the panel's 512m default reported
    // 123.8 MB available to the JVM and wrote a crash log, and NocoDB exited with
    // `Aborted (core dumped)`. Both failed as a 502 with nothing about memory
    // anywhere in the panel.
    $siteType = app(SiteTypeManager::class)->find($type);
    $expected = $siteType->defaultMemoryLimit() ?? config('server.docker.default_memory_limit');

    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    // The app's own service, not the database — which keeps the db default.
    $app = $parsed['services'][array_key_first($parsed['services'])];

    expect($app['mem_limit'])->toBe($expected);
})->with('docker apps');

it('lets the user override the app floor', function () {
    // The declared limit is what the app needs to start, not a policy about what
    // it may have. Somebody who has given Metabase 4g must keep it.
    $application = dockerAppSite('metabase');
    $application->forceFill(['memory_limit' => '4g'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['metabase']['mem_limit'])->toBe('4g');
});

it('keeps the same secrets when installed twice', function (string $type, int $port, array $roles) {
    // Retry Setup and a re-provision both call `install()` again. Generating afresh
    // each time rotates the credential in the compose file while the database keeps
    // the one it was initialised with — `POSTGRES_PASSWORD` and `MYSQL_*` apply to
    // an EMPTY data directory and are ignored afterwards.
    //
    // Measured on the test box before this test existed: NocoDB and Metabase both
    // crash-looping on "password authentication failed for user", with a compose
    // file that looked entirely correct.
    $application = dockerAppSite($type);

    $first = installDockerApp($application)->docker_secrets;
    $second = installDockerApp($application->fresh())->docker_secrets;

    expect($second)->toBe($first);
})->with('docker apps');

it('still generates a secret the app has gained since install', function () {
    // The union has to keep stored values AND fill gaps. `$stored + $fresh` does;
    // `$fresh + $stored` rotates everything, which is what I wrote first.
    $application = installDockerApp(dockerAppSite('ghost'));

    $partial = $application->docker_secrets;
    unset($partial['GHOST_DB_PASSWORD']);
    $application->forceFill(['docker_secrets' => $partial])->save();

    $after = installDockerApp($application->fresh())->docker_secrets;

    expect($after['MYSQL_ROOT_PASSWORD'])->toBe($partial['MYSQL_ROOT_PASSWORD'])
        ->and($after['GHOST_DB_PASSWORD'] ?? null)->not->toBeNull();
});

it('creates the directory before writing into it', function () {
    // `ManagedFile::put()` is `tee <path>` and nothing more, so a write into a
    // directory that does not exist fails. Measured on a real site: the bind mount
    // was correct, the config was never written, and Glance crash-looped for
    // sixteen hours on the very error the starter file exists to prevent.
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

    // Order is the whole assertion: mkdir, then tee, then chown. `tee` runs
    // elevated, so a chown before it leaves the FILE owned by root while the
    // directory looks right — measured on a real site as
    // `-rw-r--r-- 1 root root glance.yml`, which the File Manager cannot edit.
    expect($index('mkdir'))->not->toBeFalse('nothing created the config directory')
        ->and($index('tee'))->not->toBeFalse()
        ->and($index('chown'))->not->toBeFalse('the file was left root-owned')
        ->and($index('mkdir'))->toBeLessThan($index('tee'))
        ->and($index('tee'))->toBeLessThan($index('chown'));
});

it('fails provisioning when the starter file cannot be written', function () {
    // Ignoring `put()`'s result is what made the missing directory silent:
    // provisioning reported success and the container had no config.
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
    // Glance exits on boot with `reading /app/config/glance.yml: no such file or
    // directory`, and a named volume starts empty — so an empty directory is not a
    // usable start. Found by opening the site, not by any test.
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

    // The file is bind-mounted from inside the site directory, which is what
    // ComposeValidator permits and what makes the File Manager the editor.
    expect($compose)->toContain(':/app/config')
        ->and($compose)->toContain('/home/owner/site/public_html/app/config')
        // And a named volume is NOT used for it.
        ->and($application->volume_mounts)->toBe([]);

    // Something actually wrote a glance.yml — asserting on the compose file alone
    // would pass while nothing created the config, which is this exact bug.
    expect(collect($written)->contains(
        fn (array $args): bool => collect($args)->contains(fn ($a) => str_contains((string) $a, 'glance.yml'))
    ))->toBeTrue('nothing wrote glance.yml');
});

it('does not overwrite a config somebody has edited', function () {
    // A reinstall or Retry Setup must leave an edited configuration alone — the
    // same rule as the secrets, for the same reason.
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
    // Homepage answers a request whose Host header it was not told about with a
    // 400, so the container looks healthy and the site is unusable. Only opening
    // it finds that.
    $application = installDockerApp(dockerAppSite('homepage'));

    expect(Yaml::parse((string) $application->compose)['services']['app']['environment'])
        ->toHaveKey('HOMEPAGE_ALLOWED_HOSTS', $application->domain);
});

/*
 * Grafana and BookStack, and the two things about them that the shared property
 * tests above cannot see.
 */

it('never leaves Grafana on admin/admin', function () {
    // Grafana's documented quickstart is admin/admin with a change-me prompt on
    // first login. On a public URL that is a race between the owner and everyone
    // else, so the panel sets a password per site — and `GF_SECURITY_ADMIN_PASSWORD`
    // is read on every boot, so this value IS the password rather than a seed.
    $application = installDockerApp(dockerAppSite('grafana', 20101));

    $parsed = Yaml::parse((string) $application->compose);
    $environment = $parsed['services']['app']['environment'];

    $secret = $application->docker_secrets['GF_SECURITY_ADMIN_PASSWORD'];

    expect($environment['GF_SECURITY_ADMIN_PASSWORD'])->toBe($secret)
        ->and($secret)->not->toBe('admin')
        ->and(strlen($secret))->toBeGreaterThanOrEqual(24)
        // And the account it belongs to is named, so the Credentials panel and the
        // login form agree without the reader having to know Grafana's defaults.
        ->and($environment['GF_SECURITY_ADMIN_USER'])->toBe('admin');
});

it('does not quietly make every Grafana dashboard public', function () {
    // Anonymous access would turn "install Grafana" into "publish my metrics",
    // and the reporting toggles phone home. Neither is a choice to make silently
    // on somebody's behalf.
    $application = installDockerApp(dockerAppSite('grafana', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    expect($environment['GF_AUTH_ANONYMOUS_ENABLED'])->toBe('false')
        ->and($environment['GF_ANALYTICS_REPORTING_ENABLED'])->toBe('false');
});

it('gives BookStack a key Laravel will actually accept', function () {
    // The trap. `APP_KEY` must be `base64:` plus exactly 32 base64-encoded bytes;
    // passed raw, BookStack exits at boot complaining about an unsupported cipher
    // — a message that says nothing about the key being the problem.
    $application = installDockerApp(dockerAppSite('bookstack', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    $key = $environment['APP_KEY'];

    expect($key)->toStartWith('base64:');

    $decoded = base64_decode(substr($key, strlen('base64:')), true);

    // 32 BYTES after decoding, which is what the cipher requires — asserted on
    // the decoded length rather than the string's, because a 32-character
    // base64 string decodes to 24 bytes and would look right.
    expect($decoded)->not->toBeFalse()
        ->and(strlen($decoded))->toBe(32)
        ->and($decoded)->toBe($application->docker_secrets['APP_KEY']);
});

it('does not mount the database volume into the BookStack container', function () {
    // Caught while writing the template: `$mounts` holds every volume the type
    // declares, so looping it in a multi-service file hands MariaDB's data
    // directory to the application as well. The single-container template can
    // loop it because there is only one service to give them to.
    $application = installDockerApp(dockerAppSite('bookstack', 20101));
    $parsed = Yaml::parse((string) $application->compose);

    expect($parsed['services']['app']['volumes'])->toBe(['sv-app-'.$application->id.'_config:/config'])
        ->and($parsed['services']['db']['volumes'])->toBe(['sv-app-'.$application->id.'_db:/var/lib/mysql']);
});

it('tells BookStack its url, which it refuses to start without', function () {
    $application = installDockerApp(dockerAppSite('bookstack', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    expect($environment['APP_URL'])->toStartWith('https://')
        // The database host is the compose service name, not localhost — the two
        // containers share a network and nothing else.
        ->and($environment['DB_HOST'])->toBe('db');
});

it('gives every Docker app an explicitly tagged image', function () {
    // An untagged reference is `latest` by omission, which is the one form nobody
    // reading the config would notice.
    //
    // NOT asserting "never `latest`", which is what this test said first and was
    // wrong about: Metabase, NocoDB and BookStack all use it deliberately, because
    // upstream publishes only immutable exact versions and `latest`. There is no
    // line to track, and an exact pin stops being noticed while every new site
    // gets an old release.
    //
    // What this cannot check is that the tag EXISTS — that needs the registry, and
    // the one time it was skipped a bare `26.09` was invented from BookStack's
    // version pattern and the install died at `docker pull`.
    foreach (array_keys((array) config('server.docker_apps')) as $app) {
        $image = (string) config("server.docker_apps.{$app}.image");

        expect($image)->not->toBe('')
            ->and(str_contains($image, ':'))
            ->toBeTrue("{$app} has no explicit tag");
    }
});

/*
 * The container WordPress, and the two things that make it more than a template.
 */

it('teaches WordPress that the request arrived over HTTPS', function () {
    // nginx terminates TLS and proxies over plain HTTP, so PHP sees no `HTTPS` in
    // `$_SERVER`. WordPress then writes every asset URL as `http://` — blocked as
    // mixed content — and if `siteurl` says `https` the two disagree and every
    // request becomes a redirect loop. The container vhost sends the header; this
    // is the half that reads it.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));
    $extra = Yaml::parse((string) $application->compose)['services']['app']['environment']['WORDPRESS_CONFIG_EXTRA'];

    // Escaped as `$$`, which is what reaches PHP as one `$`. Written plainly,
    // Compose interpolates it away and the container gets `['HTTPS'] = 'on'` —
    // wp-config's eval() then dies and every request is a 500. Found on a real
    // box, because the YAML looks correct either way.
    expect($extra)->toContain('HTTP_X_FORWARDED_PROTO')
        ->and($extra)->toContain("\$\$_SERVER['HTTPS'] = 'on'")
        ->and($extra)->not->toContain("\n    \$_SERVER");
});

it('makes the panel domain the one source for the WordPress url', function () {
    // WordPress normally owns `siteurl` in its database, and a panel-side domain
    // change then leaves it redirecting to the old host — the lockout everybody
    // has had once. WP_HOME and WP_SITEURL come from the environment instead.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    expect($environment['WORDPRESS_SITE_URL'])->toBe('https://'.$application->domain)
        ->and($environment['WORDPRESS_CONFIG_EXTRA'])->toContain("define('WP_HOME'")
        ->and($environment['WORDPRESS_CONFIG_EXTRA'])->toContain("define('WP_SITEURL'");
});

it('follows a domain change through to the WordPress url', function () {
    // The reason `urlEnvKey()` is a real variable and not a marker: `syncUrl()`
    // returns early for a type that declares none, so the baked URL would go stale
    // and the site would keep serving the old host.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));

    app(DockerAppInstaller::class)->syncUrl($application, 'https://moved.example.com');

    $environment = Yaml::parse((string) $application->fresh()->compose)['services']['app']['environment'];

    expect($environment['WORDPRESS_SITE_URL'])->toBe('https://moved.example.com');
});

it('does not collide with the PHP WordPress it sits beside', function () {
    // `name()` is the identifier: `find()` resolves by it and the catalog's
    // translation keys are built from it, so two types called `wordpress` would be
    // one type with the other shadowed.
    $php = app(SiteTypeManager::class)->find('wordpress');
    $container = app(SiteTypeManager::class)->find('wordpress_container');

    expect($php)->not->toBeNull()->and($container)->not->toBeNull()
        ->and($php->servingProfile())->toBe('php')
        ->and($container->servingProfile())->toBe('docker')
        // Same product, same title — they are never offered together, because each
        // is filtered out of the other's stack.
        ->and(__('application.types.wordpress.title'))->toBe('WordPress')
        ->and(__('application.types.wordpress_container.title'))->toBe('WordPress');
});

it('leaves the WordPress salts to the image', function () {
    // The entrypoint generates the eight keys from /dev/urandom when they are
    // absent and writes them into wp-config.php, which lives in the volume — so
    // they are unique per site and stable. Generating them here would add eight
    // rows to the Credentials panel to replace something already correct.
    $application = installDockerApp(dockerAppSite('wordpress_container', 20101));
    $environment = Yaml::parse((string) $application->compose)['services']['app']['environment'];

    foreach (['WORDPRESS_AUTH_KEY', 'WORDPRESS_SECURE_AUTH_KEY', 'WORDPRESS_NONCE_SALT'] as $key) {
        expect($environment)->not->toHaveKey($key);
    }
});

it('escapes every dollar sign, because Compose interpolates its own file', function (string $type, int $port, array $roles) {
    // A general guard, not a WordPress one. `$NAME` and `${NAME}` are substituted
    // by Compose wherever they appear in the file, and the substitution is silent
    // — an unset variable becomes an empty string with no warning. So any template
    // that writes a shell or PHP variable has to double the dollar.
    //
    // This cost a 500 on a real WordPress site: the file said `$_SERVER[...]` and
    // the container received `[...]`.
    $compose = (string) installDockerApp(dockerAppSite($type, $port))->compose;

    // A `$` that is neither preceded nor followed by another `$`, and which starts
    // an identifier or a brace — exactly what Compose would consume.
    expect(preg_match('/(?<!\$)\$(?!\$)[A-Za-z_{]/', $compose))
        ->toBe(0, "{$type} writes an unescaped \$variable that Compose will interpolate away");
})->with('docker apps');

/*
 * Per-app CPU quotas.
 *
 * Deliberately NOT symmetric with memory, and these tests are the record of that
 * decision. An app type may declare a memory floor — Metabase needs 2g or the JVM
 * writes a crash log — because a floor is a fact about the software. There is no
 * equivalent CPU fact: an app is slower on less CPU and does not fail, so a floor
 * would be an invented policy. And a *default* would be worse than invented: it
 * would throttle every one-click app already installed on the box the next time it
 * deployed.
 */
it('gives a one-click app no cpu quota unless the user set one', function (string $type, int $port, array $roles) {
    $parsed = Yaml::parse((string) installDockerApp(dockerAppSite($type))->compose);

    foreach ($parsed['services'] as $name => $service) {
        expect($service)->not->toHaveKey('cpus', "{$name} was given a CPU quota nobody asked for");
    }
})->with('docker apps');

it('applies the user cpu quota to the app service only', function () {
    // The app's number is the app's. Splitting it across a bundled database would
    // mean guessing a ratio, and the guess is wrong for every app whose work is in
    // its database — so the engine stays unbounded, exactly as its memory ceiling
    // is a separate number rather than a share of the app's.
    $application = dockerAppSite('ghost');
    $application->forceFill(['cpu_limit' => '1.5'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['ghost']['cpus'])->toBe(1.5)
        ->and($parsed['services']['ghost_db'] ?? $parsed['services'][array_key_last($parsed['services'])])
        ->not->toHaveKey('cpus');
});

it('renders a fractional cpu quota that parses as a number', function () {
    // `cpus: 0.5` has to survive being read back by Compose's own parser. Quoted
    // or malformed it is a string, and Compose refuses the file at `up` — after
    // the site has been saved, which is the shape of failure this whole feature
    // was meant to move to the form.
    $application = dockerAppSite('metabase');
    $application->forceFill(['cpu_limit' => '0.5'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['metabase']['cpus'])->toBe(0.5);
});

it('does not let the app ceiling become the database ceiling', function () {
    // 🔴 Found on the box, and the file on disk was innocent. Ghost installed at
    // 640m wrote `mem_limit: 640m` on the app and `512m` on its MySQL — correct —
    // and `docker inspect` reported 640m on BOTH.
    //
    // The cause is that a one-click's `compose` column holds a file the PANEL
    // rendered, so `ContainerSupervisor::contents()` takes its pasted-file branch
    // and writes an override for every service in it. That override exists to give
    // a HAND-WRITTEN file the limits it has none of; applied to the panel's own
    // template it replaces per-service numbers that were already right.
    //
    // This had been true of memory since one-click apps shipped. It is asserted
    // here rather than in the supervisor's own tests because only a real app
    // template has two services with deliberately different ceilings.
    $application = dockerAppSite('ghost');
    $application->forceFill(['memory_limit' => '640m', 'cpu_limit' => '0.5'])->save();

    $parsed = Yaml::parse((string) installDockerApp($application)->compose);

    expect($parsed['services']['ghost']['mem_limit'])->toBe('640m')
        ->and($parsed['services']['ghost']['cpus'])->toBe(0.5)
        // The database keeps its own ceiling and takes no quota — which is what
        // the create form's help text promises.
        ->and($parsed['services']['db']['mem_limit'])->toBe((string) config('server.docker.default_db_memory_limit'))
        ->and($parsed['services']['db'])->not->toHaveKey('cpus');
});
