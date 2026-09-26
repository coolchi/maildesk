<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $name = fake()->unique()->domainName();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'status' => 'pending',
            'provider' => 'resend',
            // Derive from the final name so overriding 'name' keeps records consistent.
            'dns_records' => fn (array $attributes) => Domain::defaultDnsRecords($attributes['name']),
            'verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(function (array $attributes) {
            $name = $attributes['name'] ?? fake()->domainName();
            $dns = Domain::defaultDnsRecords($name);
            $dns['checks'] = ['spf' => true, 'dkim' => true, 'dmarc' => true];

            return [
                'status' => 'verified',
                'verified_at' => now()->subDays(3),
                'dns_records' => $dns,
            ];
        });
    }
}
