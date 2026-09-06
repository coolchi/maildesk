<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\AutomationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationStep>
 */
class AutomationStepFactory extends Factory
{
    protected $model = AutomationStep::class;

    public function definition(): array
    {
        return [
            'automation_id' => Automation::factory(),
            'position' => 0,
            'type' => 'email',
            'config' => ['label' => 'Send welcome email'],
        ];
    }
}
