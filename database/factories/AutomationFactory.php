<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'status' => 'paused',
            'trigger' => 'user.created',
        ];
    }
}
