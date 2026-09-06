<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'key' => 'plan_'.fake()->unique()->bothify('??##'),
            'product' => 'transactional',
            'name' => 'Pro',
            'price' => 20,
            'interval' => 'month',
            'emails' => 50000,
            'contacts' => null,
            'seats' => 10,
            'featured' => true,
            'features' => [
                ['id' => 'f1', 'label' => '50,000 emails / month', 'included' => true],
            ],
        ];
    }
}
