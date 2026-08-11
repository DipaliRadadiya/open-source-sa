<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'system_user_id' => SystemUser::factory(),
            'name' => Str::title($name),
            // Derived, never random: the slug names the web-server config file
            // and the site directory, so a factory that left it null would
            // produce applications whose document root is the system user's
            // home — the bug this project already shipped once.
            'slug' => Str::slug($name),
            'domain' => fake()->unique()->domainName(),
            'site_type' => 'php',
            'serving_profile' => 'php',
            'status' => 'active',
            'web_root' => '/',
            'php_version' => '8.4',
        ];
    }

    public function node(): static
    {
        return $this->state(fn (): array => [
            'site_type' => 'uptimekuma',
            'serving_profile' => 'node',
            'php_version' => null,
            'node_version' => '22',
        ]);
    }

    public function static(): static
    {
        return $this->state(fn (): array => [
            'site_type' => 'static',
            'serving_profile' => 'static',
            'php_version' => null,
        ]);
    }
}
