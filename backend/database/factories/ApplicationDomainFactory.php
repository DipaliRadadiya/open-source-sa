<?php

namespace Database\Factories;

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationDomain>
 */
class ApplicationDomainFactory extends Factory
{
    protected $model = ApplicationDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'domain' => fake()->unique()->domainName(),
            'type' => DomainType::Alias,
            'is_test' => false,
            'behind_proxy' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['type' => DomainType::Primary]);
    }
}
