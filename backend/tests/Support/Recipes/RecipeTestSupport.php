<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use App\Services\Server\HostCpus;
use Illuminate\Support\Facades\Process;

function rc03RecipeSetup(string $stack = 'docker'): void
{
    config([
        'recipes.path' => base_path('tests/Fixtures/recipes/valid'),
        'recipes.hook_namespaces' => ['App\\Services\\Recipes\\Hooks\\', 'Tests\\Support\\Recipes\\'],
        'server.docker.default_memory_limit' => '512m',
        'server.docker.default_db_memory_limit' => '512m',
    ]);
    app(RecipeRegistry::class)->flush();
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return match ($args[0] ?? '') {
            'cat' => Process::result(exitCode: 1, errorOutput: 'No such file'),
            'getent' => Process::result(exitCode: 2),
            default => Process::result(),
        };
    });
    app()->instance(HostCpus::class, Mockery::mock(HostCpus::class)->shouldReceive('count')->andReturn(4)->getMock());
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => $stack, 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => $stack === 'docker' ? ['docker'] : ['php', 'static']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
}

function rc03RecipeSite(string $type = 'demo_single', array $overrides = []): Application
{
    $user = SystemUser::firstOrCreate(['username' => 'owner'], ['home_path' => '/home/owner']);

    return Application::forceCreate($overrides + [
        'id' => 1, 'system_user_id' => $user->id, 'name' => 'Recipe Owner & Team'.(($overrides['id'] ?? 1) === 1 ? '' : ' '.$overrides['id']),
        'slug' => 'site', 'domain' => (($overrides['id'] ?? 1) === 1 ? 'example.test' : 'site'.$overrides['id'].'.example.test'), 'web_root' => '',
        'site_type' => $type, 'serving_profile' => 'docker', 'status' => 'pending',
        'app_port' => 20000 + ($overrides['id'] ?? 1), 'docker_secrets' => ['PASSWORD' => str_repeat('a', 32)],
        'settings' => ['admin_email' => 'owner@example.test'],
    ]);
}

function rc03RecipeInstall(Application $application): Application
{
    app(DockerAppInstaller::class)->install($application, '/home/owner/site/public_html', []);

    return $application->fresh();
}
