<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\AdministratorRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'is_admin' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Administrator: `is_admin` + the protected Administrator role attached
     * (mirrors real first-admin registration). Its permissions are present
     * only if the PermissionSeeder has run in the test.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ])->afterCreating(function (User $user) {
            $role = app(AdministratorRole::class)->ensure();
            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}
