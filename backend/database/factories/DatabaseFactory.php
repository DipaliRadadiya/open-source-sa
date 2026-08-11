<?php

namespace Database\Factories;

use App\Models\Database;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    protected $model = Database::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Identifier rules are enforced on the way in, so the factory has
            // to produce a name that would actually pass them.
            'name' => Str::slug(fake()->unique()->words(2, true), '_').'_db',
            'engine' => 'mariadb',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'application_id' => null,
        ];
    }

    public function mongo(): static
    {
        return $this->state(fn (): array => [
            'engine' => 'mongodb',
            'charset' => null,
            'collation' => null,
        ]);
    }
}
