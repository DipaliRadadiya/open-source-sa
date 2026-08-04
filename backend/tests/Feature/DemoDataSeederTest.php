<?php

use App\Models\Application;
use App\Models\SystemUser;
use Database\Seeders\DemoDataSeeder;

it('seeds demo system users and applications', function () {
    $this->seed(DemoDataSeeder::class);

    expect(SystemUser::count())->toBe(2)
        ->and(Application::count())->toBe(9);

    // Every serving profile is represented, so the sidebar's profile
    // branches all have a row to render against.
    expect(Application::pluck('serving_profile')->unique()->sort()->values()->all())
        ->toBe(['node', 'php', 'static']);

    // Every status is represented, so non-active rendering is covered.
    expect(Application::pluck('status')->map->value->unique()->sort()->values()->all())
        ->toBe(['active', 'failed', 'pending', 'provisioning']);
});

it('gives the node applications a start command so has_process is true', function () {
    $this->seed(DemoDataSeeder::class);

    $node = Application::where('serving_profile', 'node')->get();

    expect($node)->toHaveCount(2);
    $node->each(fn ($app) => expect($app->start_command)->not->toBeEmpty()
        ->and($app->app_port)->not->toBeNull());

    // The php/static rows must NOT look like processes.
    Application::where('serving_profile', '!=', 'node')->get()
        ->each(fn ($app) => expect($app->start_command)->toBeNull());
});

it('is idempotent — re-running adds nothing and edits nothing', function () {
    $this->seed(DemoDataSeeder::class);

    $before = Application::orderBy('id')->get()->map->only(['id', 'domain', 'name', 'status'])->all();
    $userCount = SystemUser::count();

    $this->seed(DemoDataSeeder::class);

    expect(Application::count())->toBe(count($before))
        ->and(SystemUser::count())->toBe($userCount)
        ->and(Application::orderBy('id')->get()->map->only(['id', 'domain', 'name', 'status'])->all())
        ->toBe($before);
});

it('leaves pre-existing rows untouched', function () {
    $existing = SystemUser::create(['username' => 'realuser', 'home_path' => '/home/realuser']);
    $realApp = Application::create([
        'system_user_id' => $existing->id,
        'name' => 'Real Site',
        'domain' => 'real.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
    ]);

    $this->seed(DemoDataSeeder::class);

    expect($realApp->refresh()->name)->toBe('Real Site')
        ->and($existing->refresh()->username)->toBe('realuser')
        ->and(Application::find($realApp->id))->not->toBeNull();
});

it('refuses to run in production', function () {
    // `environment()` reads the container's `env` binding, so overriding it
    // is what actually flips the check — detectEnvironment() re-runs the
    // resolver and does not stick.
    $this->app['env'] = 'production';

    // Run the seeder directly rather than through $this->seed(): the test
    // helper dispatches db:seed as a console command, which swallows the
    // exception into the command runner instead of surfacing it here.
    expect(fn () => (new DemoDataSeeder)->run())
        ->toThrow(RuntimeException::class);

    expect(Application::count())->toBe(0)
        ->and(SystemUser::count())->toBe(0);
});
