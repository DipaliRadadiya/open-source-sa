<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\AbstractDockerAppType;
use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use App\Services\Server\ServerOps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
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
    // Strapi was the intended second app and publishes NO official image —
    // `strapi/strapi` and `strapi/base` are both gone from Docker Hub, and
    // upstream's own guidance is to build your own from a `create-strapi-app`
    // project. Verified against the registry, not assumed. A one-click that
    // pulls a third-party rebuild is not something a server panel should ship,
    // so the second app is a decision still to be made and the dataset has one
    // entry rather than a card that 404s at `docker pull`.
]);

it('writes a compose file that parses, for each app', function (string $type, int $port, array $roles) {
    $application = installDockerApp(dockerAppSite($type));

    $parsed = Yaml::parse((string) $application->compose);

    expect($parsed)->toBeArray()
        // One service for a Group A app, two for an app with its own database.
        // Asserted as a range rather than a magic number, and the "exactly one
        // service publishes a port" test below is what actually pins the shape.
        ->and(count($parsed['services']))->toBeGreaterThanOrEqual(1)
        ->and(count($parsed['services']))->toBeLessThanOrEqual(2)
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

    foreach ($keys as $key) {
        $a = $first->docker_secrets[$key] ?? null;
        $b = $second->docker_secrets[$key] ?? null;

        expect($a)->not->toBeNull("{$key} was not generated")
            ->and(strlen($a))->toBeGreaterThanOrEqual(24)
            ->and($a)->not->toBe($b, "{$key} is the same on two sites")
            // And it actually reached the file — a stored secret the template
            // never interpolates is a credential nothing uses. See
            // `secretReachedFile()` for the one app that encodes on the way in.
            ->and(secretReachedFile((string) $first->compose, $a))
            ->toBeTrue("{$key} was stored but never reached the compose file");
    }
})->with('docker apps');

it('ships no secret in the template itself', function (string $type, int $port, array $roles) {
    // Reading the template, not the render: a default that only shows up when a
    // caller forgets to pass a value is still a shipped credential.
    $template = app(SiteTypeManager::class)->find($type)->composeTemplate();
    $path = base_path('resources/views/'.str_replace('.', '/', $template).'.blade.php');

    $source = file_get_contents($path);

    foreach (app(SiteTypeManager::class)->find($type)->generatedSecrets() as $key) {
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

it('asks for no fields, because the app has already answered', function (string $type, int $port, array $roles) {
    // `DockerSiteType` asks for an image and a port because the user is choosing
    // what to run. Here a field offering to change either would be offering to
    // break the compose file the panel is about to write.
    $siteType = app(SiteTypeManager::class)->find($type);

    expect($siteType)->toBeInstanceOf(AbstractDockerAppType::class)
        ->and($siteType->fields())->toBe([])
        ->and($siteType->needsDatabase())->toBeFalse();
})->with('docker apps');

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

    foreach ($before as $key => $value) {
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
