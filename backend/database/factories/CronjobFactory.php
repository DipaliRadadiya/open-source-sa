<?php

namespace Database\Factories;

use App\Models\Cronjob;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cronjob>
 */
class CronjobFactory extends Factory
{
    protected $model = Cronjob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            // Names the /etc/cron.d file, so two jobs sharing one would write
            // over each other.
            'slug' => Str::slug($name),
            'username' => 'root',
            'system_user_id' => null,
            'application_id' => null,
            'command' => '/usr/bin/php -v',
            'expression' => '0 3 * * *',
            'active' => true,
        ];
    }

    /** Owned by a panel-managed system user rather than an unmanaged account. */
    public function forSystemUser(): static
    {
        return $this->state(function (): array {
            $systemUser = SystemUser::factory()->create();

            return ['system_user_id' => $systemUser->id, 'username' => $systemUser->username];
        });
    }
}
