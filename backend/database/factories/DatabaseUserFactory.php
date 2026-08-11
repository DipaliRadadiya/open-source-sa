<?php

namespace Database\Factories;

use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DatabaseUser>
 */
class DatabaseUserFactory extends Factory
{
    protected $model = DatabaseUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'database_id' => Database::factory(),
            'username' => Str::slug(fake()->unique()->userName(), '_'),
            // Encrypted by the model's cast, never stored or logged plain.
            'password' => fake()->password(16, 24),
            'connection_preference' => 'localhost',
            'host' => 'localhost',
        ];
    }
}
