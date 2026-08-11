<?php

namespace Database\Factories;

use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The Linux account that owns and runs a site — not a panel login. See
 * {@see UserFactory} for the account that logs in.
 *
 * @extends Factory<SystemUser>
 */
class SystemUserFactory extends Factory
{
    protected $model = SystemUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = fake()->unique()->userName();
        // Linux usernames are lowercase, and the home path is derived from the
        // username the same way `useradd -m` derives it.
        $username = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $username) ?: 'siteowner');

        return [
            'username' => $username,
            'home_path' => "/home/{$username}",
            'shell' => '/bin/bash',
            'sudo' => false,
            'ssh_access' => false,
        ];
    }

    /** Can escalate. Off by default, because most site owners must not. */
    public function sudo(): static
    {
        return $this->state(fn (): array => ['sudo' => true]);
    }
}
