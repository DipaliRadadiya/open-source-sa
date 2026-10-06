<?php

use App\Jobs\DeployApplication;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Bug #43: a Git "PHP application" stays on web root `/`, and Laravel and
 * Symfony keep their front controller in `public/` with `.env`, the source
 * and `vendor/` beside it. So the site served `/.env` and no application.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);
});

function frameworkApp(string $webRoot = '/'): Application
{
    return Application::create([
        'system_user_id' => test()->su->id, 'name' => 'Shop', 'domain' => 'shop.example.com',
        'site_type' => 'git', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => $webRoot,
        'status' => 'active', 'repository_url' => 'https://github.com/octocat/hello.git', 'branch' => 'main',
    ]);
}

/**
 * A checkout holding exactly these files; every other command succeeds.
 *
 * @param  array<int, string>  $files  relative to the checkout
 */
function frameworkCheckout(Application $app, array $files): ArrayObject
{
    $root = rtrim($app->load('systemUser')->codePath(), '/');
    $written = new ArrayObject;

    Process::fake(function ($process) use ($root, $files, $written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-f' && str_starts_with((string) ($args[2] ?? ''), $root.'/')) {
            return Process::result(exitCode: in_array(substr($args[2], strlen($root) + 1), $files, true) ? 0 : 1);
        }

        // No symlinks in this checkout (WR-01 refuses a web root behind one).
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-L') {
            return Process::result(exitCode: 1);
        }

        if (($args[0] ?? '') === 'tee') {
            $written->append((string) $process->input);
        }

        // The deploy's last step requests the site and wants an answer.
        return Process::result(output: ($args[0] ?? '') === 'curl' ? '200' : '');
    });

    return $written;
}

it('serves public/ for a Laravel or Symfony repository', function (string $console) {
    $app = frameworkApp();
    $written = frameworkCheckout($app, ['public/index.php', $console, 'composer.json']);

    dispatch_sync(new DeployApplication($app->id));

    $root = rtrim($app->codePath(), '/');

    expect($app->fresh()->web_root)->toBe('public')
        ->and(collect($written)->contains(fn ($config) => str_contains($config, "root {$root}/public;")))->toBeTrue()
        ->and(ActivityLog::where(['type' => 'application', 'action' => 'web_root_changed'])->exists())->toBeTrue();
})->with(['Laravel' => 'artisan', 'Symfony' => 'bin/console']);

it('leaves a web root the user chose', function () {
    $app = frameworkApp('web');
    frameworkCheckout($app, ['public/index.php', 'artisan']);

    dispatch_sync(new DeployApplication($app->id));

    expect($app->fresh()->web_root)->toBe('web');
});

it('leaves a plain PHP repository on /', function (array $files) {
    $app = frameworkApp();
    frameworkCheckout($app, $files);

    dispatch_sync(new DeployApplication($app->id));

    expect(trim((string) $app->fresh()->web_root, '/'))->toBe('');
})->with([
    'index.php at the top' => [['index.php', 'public/index.php', 'artisan']],
    'no framework console' => [['public/index.php']],
    'no front controller' => [['artisan']],
]);
