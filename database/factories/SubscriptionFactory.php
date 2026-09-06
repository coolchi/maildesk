<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'key' => 'sub_'.fake()->unique()->bothify('??##'),
            'organization_id' => Organization::factory(),
            'plan_id' => null,
            'plan_name' => 'Pro',
            'product' => 'transactional',
            'status' => 'active',
            'price' => 20,
            'renews_at' => 'Oct 4, 2026',
            'seats' => 4,
        ];
    }
}
