<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Suppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
{
    protected $model = Suppression::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'reason' => 'Manual suppression',
            'source' => 'manual',
        ];
    }
}
