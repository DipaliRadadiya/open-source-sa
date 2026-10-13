<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use App\Services\Server\HostCpus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Recipes\Golden;

uses(RefreshDatabase::class);

beforeEach(function () {
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
    app()->instance(HostCpus::class, new class extends HostCpus
    {
        public function count(): int
        {
            return 4;
        }
    });
    app()->setLocale('en');
});

function rc09GoldenSite(string $slug, array $overrides = []): Application
{
    $owner = SystemUser::firstOrCreate(['username' => 'owner'], ['home_path' => '/home/owner']);
    $type = app(SiteTypeManager::class)->find($slug);
    $secrets = [];
    foreach ($type->generatedSecrets() as $key) {
        $secrets[$key] = Golden::secret($slug, $key);
    }

    return Application::forceCreate($overrides + [
        'id' => Golden::APP_ID,
        'system_user_id' => $owner->id,
        'name' => 'Golden '.$slug,
        'slug' => 'golden-'.str_replace('_', '-', $slug),
        'domain' => Golden::domain($slug),
        'web_root' => 'public_html',
        'site_type' => $slug,
        'serving_profile' => 'docker',
        'status' => 'pending',
        'app_port' => Golden::APP_PORT,
        'docker_secrets' => $secrets,
        'settings' => in_array($slug, ['directus'], true) ? ['admin_email' => 'owner@golden.test'] : null,
    ]);
}

function rc09GoldenInstall(Application $application): Application
{
    app(DockerAppInstaller::class)->install($application, Golden::DOCROOT, []);

    return $application->fresh();
}

dataset('new golden apps', array_combine(['memos', 'paperless', 'langflow', 'umami', 'odoo', 'immich', 'twenty', 'affine', 'penpot', 'openclaw', 'dify', 'codeserver', 'directus', 'docuseal', 'listmonk', 'keycloak', 'infisical', 'litellm', 'librechat', 'activepieces', 'appsmith', 'docmost', 'siyuan', 'joplin', 'karakeep', 'meilisearch', 'hasura', 'cyberchef', 'cloudreve'], array_map(fn ($slug) => [$slug], ['memos', 'paperless', 'langflow', 'umami', 'odoo', 'immich', 'twenty', 'affine', 'penpot', 'openclaw', 'dify', 'codeserver', 'directus', 'docuseal', 'listmonk', 'keycloak', 'infisical', 'litellm', 'librechat', 'activepieces', 'appsmith', 'docmost', 'siyuan', 'joplin', 'karakeep', 'meilisearch', 'hasura', 'cyberchef', 'cloudreve'])));

it('renders the same compose on install', function (string $slug) {
    Golden::assertMatches(Golden::dir($slug).'/install-default.yml', rc09GoldenInstall(rc09GoldenSite($slug))->compose);
})->with('new golden apps');

it('renders the same compose with limits', function (string $slug) {
    $installed = rc09GoldenInstall(rc09GoldenSite($slug, ['memory_limit' => '640m', 'cpu_limit' => '1.5']));
    Golden::assertMatches(Golden::dir($slug).'/install-limits.yml', $installed->compose);
})->with('new golden apps');

it('re-renders the same compose when the url is synced', function (string $slug) {
    $installed = rc09GoldenInstall(rc09GoldenSite($slug));
    $before = $installed->compose;
    app(DockerAppInstaller::class)->syncUrl($installed->fresh(), 'http://'.Golden::domain($slug));
    $after = $installed->fresh()->compose;

    if (app(SiteTypeManager::class)->find($slug)->urlEnvKey() !== null) {
        Golden::assertMatches(Golden::dir($slug).'/sync-url.yml', $after);
    } else {
        expect($after)->toBe($before)
            ->and(is_file(Golden::dir($slug).'/sync-url.yml'))->toBeFalse();
    }
})->with('new golden apps');

it('installs twice to the same file', function (string $slug) {
    $installed = rc09GoldenInstall(rc09GoldenSite($slug));
    $again = rc09GoldenInstall($installed);
    expect($again->compose)->toBe($installed->compose)
        ->and($again->compose)->toBe(file_get_contents(Golden::dir($slug).'/install-default.yml'));
})->with('new golden apps');

it('describes the app the same way', function (string $slug) {
    $type = app(SiteTypeManager::class)->find($slug);
    $installed = rc09GoldenInstall(rc09GoldenSite($slug));
    $meta = [
        'slug' => $slug,
        'container_port' => $type->containerPort(),
        'volume_roles' => $type->volumeRoles(),
        'generated_secrets' => $type->generatedSecrets(),
        'complex_secrets' => $type->complexSecrets(),
        'panel_only_secrets' => $type->panelOnlySecrets(),
        'url_env_key' => $type->urlEnvKey(),
        'default_memory_limit' => $type->defaultMemoryLimit(),
        'method' => $type->method(),
        'serving_profile' => $type->servingProfile(),
        'needs_database' => $type->needsDatabase(),
        'features' => $type->features(),
        'category' => $type->category(),
        'icon' => $type->icon(),
        'popular' => $type->popular(),
        'fields' => $type->fields(),
        'rule_keys' => array_keys($type->rules()),
        'starter_files' => $type->starterFiles(),
        'first_run_claim' => $type->firstRunClaim($installed),
        'volume_mounts' => $installed->volume_mounts,
        'docker_secret_keys' => array_keys($installed->docker_secrets),
        'installer' => get_class(app(InstallerManager::class)->installerForType($slug)),
    ];
    $catalog = [];
    $entryEn = null;
    foreach (Golden::LOCALES as $locale) {
        app()->setLocale($locale);
        $entry = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', $slug);
        $catalog[$locale] = Arr::only($entry, ['title', 'tagline']);
        if ($locale === 'en') {
            $entryEn = $entry;
        }
    }
    app()->setLocale('en');
    $meta['catalog'] = $catalog;
    $meta['catalog_en'] = Arr::only($entryEn, ['name', 'icon', 'category', 'popular', 'method', 'serving_profile', 'needs_database', 'accepted_engines', 'has_installer', 'available']);

    Golden::assertMatches(Golden::dir($slug).'/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
})->with('new golden apps');

it('keeps Memos data in its named volume without generated secrets', function () {
    $type = app(SiteTypeManager::class)->find('memos');
    $yaml = Yaml::parse(rc09GoldenInstall(rc09GoldenSite('memos'))->compose);
    expect($type->generatedSecrets())->toBe([])
        ->and($type->urlEnvKey())->toBeNull()
        ->and($type->recipe()->firstRun)->toBe(['kind' => 'open_registration', 'credentials' => null])
        ->and($yaml['services']['app']['image'])->toBe('neosmemo/memos:0.31.0')
        ->and($yaml['services']['app']['ports'])->toBe(['127.0.0.1:20101:5230'])
        ->and($yaml['services']['app']['volumes'])->toBe(['sv-app-4242_data:/var/opt/memos']);
});

it('uses the documented Paperless database engine and first boot credentials', function () {
    config(['server.docker.default_db_memory_limit' => '384m']);
    $type = app(SiteTypeManager::class)->find('paperless');
    $site = rc09GoldenInstall(rc09GoldenSite('paperless', ['cpu_limit' => '1.5']));
    $yaml = Yaml::parse($site->compose);
    $app = $yaml['services']['webserver'];
    expect($app['environment'])->toBe([
        'PAPERLESS_REDIS' => 'redis://broker:6379',
        'PAPERLESS_DBENGINE' => 'postgresql',
        'PAPERLESS_DBHOST' => 'db',
        'PAPERLESS_DBPASS' => Golden::secret('paperless', 'DATABASE_PASSWORD'),
        'PAPERLESS_SECRET_KEY' => Golden::secret('paperless', 'PAPERLESS_SECRET_KEY'),
        'PAPERLESS_URL' => 'https://paperless.golden.test',
        'PAPERLESS_ADMIN_USER' => 'admin',
        'PAPERLESS_ADMIN_PASSWORD' => Golden::secret('paperless', 'ADMIN_PASSWORD'),
    ])->and($yaml['services']['db']['environment'])->toBe([
        'POSTGRES_DB' => 'paperless', 'POSTGRES_USER' => 'paperless',
        'POSTGRES_PASSWORD' => Golden::secret('paperless', 'DATABASE_PASSWORD'),
    ])->and($app['mem_limit'])->toBe('1g')
        ->and((string) $app['cpus'])->toBe('1.5')
        ->and($app['ports'])->toBe(['127.0.0.1:20101:8000'])
        ->and($type->panelOnlySecrets())->toBe([])
        ->and($type->recipe()->firstRun)->toBe([
            'kind' => 'credentials',
            'credentials' => [['label' => 'username', 'value' => 'admin'], ['label' => 'password', 'secret' => 'ADMIN_PASSWORD']],
        ]);
    foreach (['broker', 'db'] as $name) {
        expect($yaml['services'][$name]['mem_limit'])->toBe('384m')
            ->and($yaml['services'][$name])->not->toHaveKey('ports')->not->toHaveKey('cpus');
    }
});

it('ships all eight new recipe taglines with the images verified on both supported OS versions', function (string $slug) {
    $recipe = app(SiteTypeManager::class)->find($slug)->recipe();
    expect(array_keys($recipe->taglines))->toBe(Golden::LOCALES)
        ->and($recipe->hook)->toBeNull()->and($recipe->afterInstall)->toBeNull();
    foreach ($recipe->images as $image) {
        expect($image['tested'])->toBe([$image['ref']]);
    }
})->with('new golden apps');
