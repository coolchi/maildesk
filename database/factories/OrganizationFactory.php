<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('##'),
            'default_provider' => 'resend',
            'status' => 'active',
            'plan' => 'Pro',
            'product' => 'transactional',
            'subdomain' => Str::slug(fake()->unique()->domainWord()),
            'custom_domain' => null,
            'mrr' => 20,
            'seats' => 4,
            'emails_30d' => fake()->numberBetween(100, 50000),
            'region' => 'us-east-1',
            'owner_name' => fake()->name(),
            'owner_email' => fake()->safeEmail(),
            'mail_provider_id' => null,
            'provisioned_at' => now()->subMonths(2),
        ];
    }
}
