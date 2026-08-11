<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            // De-duped case-insensitively via the slug, so it cannot be random.
            'slug' => Str::slug($name),
            // System roles cannot be renamed, deleted or have their
            // permissions edited — never the default for a test fixture.
            'is_system' => false,
        ];
    }
}
