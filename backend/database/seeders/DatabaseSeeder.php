<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Permissions + Administrator role first, so the dev admin below
        // gets a complete Administrator role.
        $this->call(PermissionSeeder::class);

        User::factory()->admin()->create([
            'name' => 'Test Admin',
            'username' => 'admin',
        ]);
    }
}
