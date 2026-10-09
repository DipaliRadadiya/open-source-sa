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

function goldenSite(string $slug, array $overrides = []): Application
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
        'settings' => $slug === 'chatwoot' ? ['admin_email' => 'owner@golden.test'] : null,
    ]);
}

function goldenInstall(Application $application): Application
{
    app(DockerAppInstaller::class)->install($application, Golden::DOCROOT, []);

    return $application->fresh();
}

dataset('golden apps', array_combine(Golden::LEGACY, array_map(fn ($slug) => [$slug], Golden::LEGACY)));

it('renders the same compose on install', function (string $slug) {
    Golden::assertMatches(Golden::dir($slug).'/install-default.yml', goldenInstall(goldenSite($slug))->compose);
})->with('golden apps');

it('renders the same compose with limits', function (string $slug) {
    $installed = goldenInstall(goldenSite($slug, ['memory_limit' => '640m', 'cpu_limit' => '1.5']));
    Golden::assertMatches(Golden::dir($slug).'/install-limits.yml', $installed->compose);
})->with('golden apps');

it('re-renders the same compose when the url is synced', function (string $slug) {
    $installed = goldenInstall(goldenSite($slug));
    $before = $installed->compose;
    app(DockerAppInstaller::class)->syncUrl($installed->fresh(), 'http://'.Golden::domain($slug));
    $after = $installed->fresh()->compose;

    if (app(SiteTypeManager::class)->find($slug)->urlEnvKey() !== null) {
        Golden::assertMatches(Golden::dir($slug).'/sync-url.yml', $after);
    } else {
        expect($after)->toBe($before)
            ->and(is_file(Golden::dir($slug).'/sync-url.yml'))->toBeFalse();
    }
})->with('golden apps');

it('installs twice to the same file', function (string $slug) {
    $installed = goldenInstall(goldenSite($slug));
    $again = goldenInstall($installed);
    expect($again->compose)->toBe($installed->compose)
        ->and($again->compose)->toBe(file_get_contents(Golden::dir($slug).'/install-default.yml'));
})->with('golden apps');

it('describes the app the same way', function (string $slug) {
    $type = app(SiteTypeManager::class)->find($slug);
    $installed = goldenInstall(goldenSite($slug));
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
})->with('golden apps');
