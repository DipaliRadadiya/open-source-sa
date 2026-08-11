<?php

namespace Database\Factories;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Application;
use App\Models\Certificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active,
            'domains' => [fake()->unique()->domainName()],
            'force_https' => false,
            'auto_renew' => true,
            'issued_at' => now()->subDays(10),
            'expires_at' => now()->addDays(80),
        ];
    }

    /** Inside the renewal window — what the expiry checks look for. */
    public function expiringSoon(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->addDays(5)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => CertificateStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }
}
